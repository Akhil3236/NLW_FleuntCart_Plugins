<?php
/**
 * Plugin Name: FCD Fundraising
 * Description: Fundraising projecten met donatieformulier in de huisstijl (navy kaart + staalblauwe voortgang). Admin beheert projecten, ziet ingezamelde bedragen en betalingen, en kiest de voortgangs-template. Betalingen lopen via de bestaande fluentcart-donations Mollie-flow en worden per project gelabeld en betrouwbaar gereconcilieerd.
 * Version:     2.4.1
 * Author:      NextLevelWeb
 * License:     GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FCD_FR_OPT_PROJECTS', 'fcd_fundraising_projects' );
define( 'FCD_FR_OPT_TEMPLATE', 'fcd_fundraising_template' );
define( 'FCD_FR_CRON', 'fcd_fundraising_reconcile' );

/* =========================================================================
 * ACTIVATION — payments table + cron
 * ========================================================================= */
register_activation_hook( __FILE__, function () {
	global $wpdb;
	$table   = $wpdb->prefix . 'fcd_fundraising_payments';
	$charset = $wpdb->get_charset_collate();
	$sql = "CREATE TABLE {$table} (
		id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		payment_id  VARCHAR(64)  NOT NULL,
		project     VARCHAR(190) NOT NULL,
		amount      INT UNSIGNED NOT NULL DEFAULT 0,
		currency    VARCHAR(3)   NOT NULL DEFAULT 'EUR',
		status      VARCHAR(20)  NOT NULL DEFAULT 'open',
		donor_email VARCHAR(190) NULL,
		paid_at     DATETIME     NULL,
		created_at  DATETIME     NOT NULL,
		PRIMARY KEY (id),
		UNIQUE KEY payment_id (payment_id),
		KEY project (project),
		KEY status (status)
	) {$charset};";
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	if ( ! wp_next_scheduled( FCD_FR_CRON ) ) {
		wp_schedule_event( time() + 60, 'fcd_fr_five_minutes', FCD_FR_CRON );
	}
} );
register_deactivation_hook( __FILE__, function () {
	$ts = wp_next_scheduled( FCD_FR_CRON );
	if ( $ts ) {
		wp_unschedule_event( $ts, FCD_FR_CRON );
	}
} );
add_filter( 'cron_schedules', function ( $s ) {
	if ( empty( $s['fcd_fr_five_minutes'] ) ) {
		$s['fcd_fr_five_minutes'] = array( 'interval' => 300, 'display' => 'Every 5 Minutes' );
	}
	return $s;
} );

/* =========================================================================
 * DATA HELPERS
 * ========================================================================= */
function fcd_fr_projects() {
	$p = get_option( FCD_FR_OPT_PROJECTS, array() );
	if ( ! is_array( $p ) ) {
		return array();
	}
	// Normalize: guarantee keys and migrate v1 entries ('raised') to v2 ('base').
	$out = array();
	foreach ( $p as $row ) {
		if ( ! is_array( $row ) || empty( $row['name'] ) ) {
			continue;
		}
		$out[] = array(
			'name' => (string) $row['name'],
			'goal' => isset( $row['goal'] ) ? max( 1, (float) $row['goal'] ) : 1,
			'base' => isset( $row['base'] ) ? max( 0, (float) $row['base'] )
				: ( isset( $row['raised'] ) ? max( 0, (float) $row['raised'] ) : 0 ),
		);
	}
	return $out;
}
function fcd_fr_template() {
	$t = get_option( FCD_FR_OPT_TEMPLATE, 'ring' );
	return in_array( $t, array( 'pot', 'thermo', 'ring', 'bar' ), true ) ? $t : 'ring';
}
/** paid cents collected per project (from reconciled payments table) */
function fcd_fr_collected() {
	global $wpdb;
	$table = $wpdb->prefix . 'fcd_fundraising_payments';
	$rows  = $wpdb->get_results( "SELECT project, SUM(amount) AS cents FROM {$table} WHERE status='paid' GROUP BY project", ARRAY_A );
	$out   = array();
	foreach ( (array) $rows as $r ) {
		$out[ $r['project'] ] = (int) $r['cents'];
	}
	return $out;
}
function fcd_fr_mollie_key() {
	$s = get_option( 'fct_payment_mollie_settings', array() );
	if ( is_array( $s ) && ! empty( $s['api_key'] ) ) {
		return (string) $s['api_key'];
	}
	if ( defined( 'FCD_MOLLIE_API_KEY' ) && FCD_MOLLIE_API_KEY ) {
		return (string) FCD_MOLLIE_API_KEY;
	}
	return '';
}

/* =========================================================================
 * RECONCILE — pull Mollie payments tagged with a project into our table.
 * Idempotent on payment_id (unique key + ON DUPLICATE KEY UPDATE).
 * Runs: cron 5-min, admin page loads (throttled), manual button.
 * ========================================================================= */
add_action( FCD_FR_CRON, 'fcd_fr_reconcile' );

function fcd_fr_reconcile() {
	$stats = array( 'listed' => 0, 'upserted' => 0, 'errors' => 0 );
	$key   = fcd_fr_mollie_key();
	if ( '' === $key ) {
		$stats['errors']++;
		update_option( 'fcd_fr_last_reconcile', array( 'time' => time(), 'stats' => $stats ), false );
		return $stats;
	}

	global $wpdb;
	$table    = $wpdb->prefix . 'fcd_fundraising_payments';
	$since_ts = time() - (int) apply_filters( 'fcd_fr_reconcile_window', 60 * DAY_IN_SECONDS );
	$next     = add_query_arg( array( 'limit' => 100 ), 'https://api.mollie.com/v2/payments' );
	$pages    = 0;

	while ( $next && $pages < 5 ) {
		$pages++;
		$res = wp_remote_get( $next, array(
			'headers' => array( 'Authorization' => 'Bearer ' . $key ),
			'timeout' => 15,
		) );
		if ( is_wp_error( $res ) || wp_remote_retrieve_response_code( $res ) >= 300 ) {
			$stats['errors']++;
			break;
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		$list = $body['_embedded']['payments'] ?? array();
		$stop = false;

		foreach ( $list as $p ) {
			if ( ! is_array( $p ) || empty( $p['id'] ) ) {
				continue;
			}
			if ( ! empty( $p['createdAt'] ) && strtotime( $p['createdAt'] ) < $since_ts ) {
				$stop = true;
				break;
			}
			$project = $p['metadata']['project'] ?? '';
			if ( '' === $project ) {
				continue; // not a fundraising payment
			}
			$stats['listed']++;

			$cents  = (int) round( ( (float) ( $p['amount']['value'] ?? 0 ) ) * 100 );
			$status = sanitize_text_field( $p['status'] ?? 'open' );
			$email  = sanitize_email( $p['metadata']['donor_email'] ?? ( $p['billingAddress']['email'] ?? '' ) );
			$paid   = ! empty( $p['paidAt'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( $p['paidAt'] ) ) : null;
			$made   = ! empty( $p['createdAt'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( $p['createdAt'] ) ) : current_time( 'mysql', true );

			$ok = $wpdb->query( $wpdb->prepare(
				"INSERT INTO {$table} (payment_id, project, amount, currency, status, donor_email, paid_at, created_at)
				 VALUES (%s,%s,%d,%s,%s,%s,%s,%s)
				 ON DUPLICATE KEY UPDATE status=VALUES(status), paid_at=VALUES(paid_at), amount=VALUES(amount)",
				$p['id'], sanitize_text_field( $project ), $cents,
				sanitize_text_field( $p['amount']['currency'] ?? 'EUR' ),
				$status, $email, $paid, $made
			) );
			if ( false === $ok ) {
				$stats['errors']++;
			} else {
				$stats['upserted']++;
			}
		}
		if ( $stop ) {
			break;
		}
		$next = $body['_links']['next']['href'] ?? '';
	}

	update_option( 'fcd_fr_last_reconcile', array( 'time' => time(), 'stats' => $stats ), false );
	return $stats;
}

/* throttled auto-reconcile while admins work */
add_action( 'admin_init', function () {
	if ( wp_doing_ajax() || wp_doing_cron() ) {
		return;
	}
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}
	if ( get_transient( 'fcd_fr_admin_lock' ) ) {
		return;
	}
	set_transient( 'fcd_fr_admin_lock', 1, 120 );
	add_action( 'shutdown', function () {
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		fcd_fr_reconcile();
	}, 99 );
} );

/* =========================================================================
 * TAG DONATIONS WITH PROJECT — uses the donation plugin's own filter.
 * ========================================================================= */
add_filter( 'fluentcart_donations/mollie_direct/payment_args', function ( $args ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	$project = isset( $_POST['fcd_project'] ) ? sanitize_text_field( wp_unslash( $_POST['fcd_project'] ) ) : '';
	if ( '' !== $project ) {
		$args['metadata']['project'] = $project;
	}
	return $args;
} );

/* =========================================================================
 * PAYMENT ENDPOINT — self-contained Mollie payment creation via admin-ajax.
 * No dependency on the donations plugin's REST route, works on any site
 * with a Mollie key. Tags metadata.source = fcd-mollie-pay so the
 * fcd-onetime-sync plugin creates the matching FluentCart order, and
 * metadata.project so fundraising totals attribute correctly.
 * ========================================================================= */
add_action( 'wp_ajax_fcd_fr_pay', 'fcd_fr_handle_pay' );
add_action( 'wp_ajax_nopriv_fcd_fr_pay', 'fcd_fr_handle_pay' );

function fcd_fr_handle_pay() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- public payment initiation for anonymous donors; guarded by amount validation + per-request lock.
	$amount  = isset( $_POST['pwyw_amount'] ) ? (float) $_POST['pwyw_amount'] : 0;
	$project = isset( $_POST['fcd_project'] ) ? sanitize_text_field( wp_unslash( $_POST['fcd_project'] ) ) : '';
	$crid    = isset( $_POST['client_request_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_request_id'] ) ) : '';
	$page    = isset( $_POST['page_url'] ) ? esc_url_raw( wp_unslash( $_POST['page_url'] ) ) : '';
	// Both optional: donor may leave either or both blank.
	$donor_email = isset( $_POST['donor_email'] ) ? sanitize_email( wp_unslash( $_POST['donor_email'] ) ) : '';
	$donor_name  = isset( $_POST['donor_name'] ) ? sanitize_text_field( wp_unslash( $_POST['donor_name'] ) ) : '';
	// phpcs:enable

	if ( $amount < 1 || $amount > 100000 ) {
		wp_send_json_error( array( 'message' => 'Ongeldig bedrag.' ), 400 );
	}
	if ( '' === $project ) {
		wp_send_json_error( array( 'message' => 'Geen project gekozen.' ), 400 );
	}

	// Duplicate-click lock on the client request id.
	if ( $crid ) {
		$lock = 'fcd_fr_pay_' . md5( $crid );
		if ( get_transient( $lock ) ) {
			wp_send_json_error( array( 'message' => 'Deze aanvraag is al in behandeling.' ), 429 );
		}
		set_transient( $lock, 1, 60 );
	}

	$key = fcd_fr_mollie_key();
	if ( '' === $key ) {
		wp_send_json_error( array( 'message' => 'Mollie is niet geconfigureerd op deze site.' ), 500 );
	}

	// Redirect the donor back to the page they came from (same host only).
	$redirect = home_url( '/' );
	if ( $page && wp_parse_url( $page, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ) ) {
		$redirect = $page;
	}
	$redirect = add_query_arg( 'fcd_donation', 'paid', $redirect );

	$args = array(
		'amount'      => array( 'currency' => 'EUR', 'value' => number_format( $amount, 2, '.', '' ) ),
		'description' => sprintf( 'Donatie €%s — %s', number_format( $amount, 2, ',', '' ), $project ),
		'redirectUrl' => $redirect,
		'metadata'    => array(
			'source'            => 'fcd-mollie-pay',
			'project'           => $project,
			'client_request_id' => $crid,
			'donor_email'       => $donor_email, // optional — used by fcd-onetime-sync for the FluentCart customer
			'donor_name'        => $donor_name,  // optional — falls back to the Mollie bank/card holder name if blank
		),
	);

	// Webhook for instant order sync — only when a target is known.
	if ( defined( 'FCT_MOLLIE_WEBHOOK_BASE' ) && FCT_MOLLIE_WEBHOOK_BASE ) {
		$args['webhookUrl'] = rtrim( FCT_MOLLIE_WEBHOOK_BASE, '/' ) . '/?fcd-onetime-sync=mollie-ipn';
	} elseif ( function_exists( 'fcd_sync_webhook_url' ) ) {
		$args['webhookUrl'] = fcd_sync_webhook_url();
	}

	$args = apply_filters( 'fcd_fr_payment_args', $args );

	$res = wp_remote_post( 'https://api.mollie.com/v2/payments', array(
		'headers' => array(
			'Authorization' => 'Bearer ' . $key,
			'Content-Type'  => 'application/json',
		),
		'body'    => wp_json_encode( $args ),
		'timeout' => 15,
	) );

	if ( is_wp_error( $res ) ) {
		wp_send_json_error( array( 'message' => 'Kon Mollie niet bereiken. Probeer het opnieuw.' ), 502 );
	}
	$code = wp_remote_retrieve_response_code( $res );
	$body = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( $code >= 300 || empty( $body['_links']['checkout']['href'] ) ) {
		$msg = is_array( $body ) && ! empty( $body['detail'] ) ? $body['detail'] : 'Betaling kon niet worden aangemaakt.';
		wp_send_json_error( array( 'message' => $msg ), 502 );
	}

	// Record instantly as 'open' so the Fundraising admin shows it right away.
	global $wpdb;
	$table = $wpdb->prefix . 'fcd_fundraising_payments';
	$wpdb->query( $wpdb->prepare(
		"INSERT INTO {$table} (payment_id, project, amount, currency, status, donor_email, paid_at, created_at)
		 VALUES (%s,%s,%d,%s,%s,%s,NULL,%s)
		 ON DUPLICATE KEY UPDATE status=VALUES(status)",
		$body['id'], $project, (int) round( $amount * 100 ), 'EUR',
		sanitize_text_field( $body['status'] ?? 'open' ), $donor_email, gmdate( 'Y-m-d H:i:s' )
	) );

	wp_send_json_success( array( 'checkout_url' => $body['_links']['checkout']['href'] ) );
}

/* =========================================================================
 * ADMIN — top-level Fundraising menu
 * ========================================================================= */
add_action( 'admin_menu', function () {
	add_menu_page( 'Fundraising', 'Fundraising', 'manage_options', 'fcd-fundraising', 'fcd_fr_admin_page', 'dashicons-chart-pie', 57 );
} );

function fcd_fr_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	global $wpdb;
	$table = $wpdb->prefix . 'fcd_fundraising_payments';

	/* save projects + template */
	if ( isset( $_POST['fcd_fr_save'] ) && check_admin_referer( 'fcd_fr_save' ) ) {
		$names  = array_map( 'sanitize_text_field', wp_unslash( $_POST['p_name'] ?? array() ) );
		$goals  = array_map( 'floatval', wp_unslash( $_POST['p_goal'] ?? array() ) );
		$bases  = array_map( 'floatval', wp_unslash( $_POST['p_base'] ?? array() ) );
		$out    = array();
		foreach ( $names as $i => $n ) {
			$n = trim( $n );
			if ( '' === $n ) {
				continue;
			}
			$out[] = array(
				'name' => $n,
				'goal' => max( 1, $goals[ $i ] ?? 1 ),
				'base' => max( 0, $bases[ $i ] ?? 0 ), // startbedrag (offline giften e.d.)
			);
		}
		update_option( FCD_FR_OPT_PROJECTS, $out, false );

		$tpl = sanitize_text_field( wp_unslash( $_POST['fcd_fr_tpl'] ?? 'ring' ) );
		update_option( FCD_FR_OPT_TEMPLATE, in_array( $tpl, array( 'pot', 'thermo', 'ring', 'bar' ), true ) ? $tpl : 'ring', false );

		echo '<div class="notice notice-success"><p>Opgeslagen.</p></div>';
	}

	if ( isset( $_POST['fcd_fr_reconcile'] ) && check_admin_referer( 'fcd_fr_save' ) ) {
		$s = fcd_fr_reconcile();
		echo '<div class="notice notice-success"><p>Betalingen vernieuwd: ' . esc_html(
			sprintf( '%d gevonden, %d bijgewerkt, %d fouten', $s['listed'], $s['upserted'], $s['errors'] )
		) . '</p></div>';
	}

	$projects  = fcd_fr_projects();
	$collected = fcd_fr_collected();
	$tpl       = fcd_fr_template();
	$last      = get_option( 'fcd_fr_last_reconcile', array() );
	$payments  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 50", ARRAY_A );
	$eur       = function ( $cents ) { return '€ ' . number_format( $cents / 100, 2, ',', '.' ); };
	?>
	<div class="wrap">
		<h1>Fundraising</h1>
		<p>Projecten voor <code>[fcd_fundraising]</code>. <em>Ingezameld</em> = startbedrag + betaalde online giften (automatisch uit Mollie).</p>

		<form method="post">
			<?php wp_nonce_field( 'fcd_fr_save' ); ?>

			<h2>Projecten</h2>
			<table class="widefat striped" style="max-width:960px" id="fcdfr-table">
				<thead><tr>
					<th>Projectnaam</th>
					<th style="width:120px">Doel (€)</th>
					<th style="width:130px">Startbedrag (€)</th>
					<th style="width:140px">Online betaald</th>
					<th style="width:150px">Totaal ingezameld</th>
					<th style="width:40px"></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $projects as $p ) :
					$paid  = $collected[ $p['name'] ] ?? 0;
					$total = (int) round( $p['base'] * 100 ) + $paid; ?>
					<tr>
						<td><input type="text" name="p_name[]" value="<?php echo esc_attr( $p['name'] ); ?>" style="width:100%"></td>
						<td><input type="number" step="0.01" name="p_goal[]" value="<?php echo esc_attr( $p['goal'] ); ?>" min="1" style="width:100%"></td>
						<td><input type="number" step="0.01" name="p_base[]" value="<?php echo esc_attr( $p['base'] ); ?>" min="0" style="width:100%"></td>
						<td><?php echo esc_html( $eur( $paid ) ); ?></td>
						<td><strong><?php echo esc_html( $eur( $total ) ); ?></strong></td>
						<td><button type="button" class="button fcdfr-del">×</button></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p><button type="button" class="button" id="fcdfr-add">+ Project toevoegen</button></p>

			<h2>Voortgangs-template</h2>
			<p>
				<?php foreach ( array( 'pot' => '💰 Spaarpot', 'thermo' => '🌡️ Thermometer', 'ring' => '◔ Ring', 'bar' => '▬ Balk' ) as $k => $lbl ) : ?>
					<label style="margin-right:22px">
						<input type="radio" name="fcd_fr_tpl" value="<?php echo esc_attr( $k ); ?>" <?php checked( $tpl, $k ); ?>>
						<?php echo esc_html( $lbl ); ?>
					</label>
				<?php endforeach; ?>
			</p>

			<p>
				<button type="submit" name="fcd_fr_save" class="button button-primary">Opslaan</button>
				<button type="submit" name="fcd_fr_reconcile" class="button">Betalingen nu vernieuwen</button>
				<?php if ( ! empty( $last['time'] ) ) : ?>
					<span style="color:#666;margin-left:10px">Laatst vernieuwd: <?php echo esc_html( gmdate( 'Y-m-d H:i:s', $last['time'] ) ); ?> UTC</span>
				<?php endif; ?>
			</p>
		</form>

		<h2>Betalingen <small style="font-weight:normal;color:#888">(laatste 50)</small></h2>
		<?php if ( ! $payments ) : ?>
			<p style="color:#888">Nog geen fundraising-betalingen gevonden. Deze verschijnen automatisch zodra donaties via het widget binnenkomen.</p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:1100px;font-size:13px">
				<thead><tr><th>Datum</th><th>Project</th><th>Bedrag</th><th>Status</th><th>E-mail</th><th>Mollie ID</th></tr></thead>
				<tbody>
				<?php foreach ( $payments as $pm ) :
					$color = 'paid' === $pm['status'] ? '#2e7d32' : ( in_array( $pm['status'], array( 'failed', 'canceled', 'expired' ), true ) ? '#c62828' : '#c58a11' ); ?>
					<tr>
						<td><?php echo esc_html( $pm['created_at'] ); ?></td>
						<td><?php echo esc_html( $pm['project'] ); ?></td>
						<td><?php echo esc_html( $eur( (int) $pm['amount'] ) ); ?></td>
						<td style="color:<?php echo esc_attr( $color ); ?>;font-weight:700"><?php echo esc_html( $pm['status'] ); ?></td>
						<td><?php echo esc_html( $pm['donor_email'] ); ?></td>
						<td><code><?php echo esc_html( $pm['payment_id'] ); ?></code></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h2 style="margin-top:20px">Gebruik</h2>
		<p><code>[fcd_fundraising]</code> — gebruikt de gekozen template. Override per pagina: <code>[fcd_fundraising template="pot"]</code>. Eigen bedragen: <code>[fcd_fundraising amounts="25,50,100,250"]</code>. Vast project (dropdown verborgen, hoofdletterongevoelig): <code>[fcd_fundraising project="Projectnaam"]</code>.</p>

		<script>
		document.getElementById('fcdfr-add').addEventListener('click',function(){
			var tb=document.querySelector('#fcdfr-table tbody');
			var tr=document.createElement('tr');
			tr.innerHTML='<td><input type="text" name="p_name[]" style="width:100%"></td>'+
				'<td><input type="number" step="0.01" name="p_goal[]" min="1" value="1000" style="width:100%"></td>'+
				'<td><input type="number" step="0.01" name="p_base[]" min="0" value="0" style="width:100%"></td>'+
				'<td>—</td><td>—</td>'+
				'<td><button type="button" class="button fcdfr-del">×</button></td>';
			tb.appendChild(tr);
		});
		document.getElementById('fcdfr-table').addEventListener('click',function(e){
			if(e.target.classList.contains('fcdfr-del')){e.target.closest('tr').remove();}
		});
		</script>
	</div>
	<?php
}

/* =========================================================================
 * SHORTCODE — matches the site's design language:
 * navy form card ("Doe je mee?") + steel-blue progress panel, green Geef.
 * ========================================================================= */
add_shortcode( 'fcd_fundraising', 'fcd_fr_shortcode' );

/* -------------------------------------------------------------------------
 * BUILDER FALLBACK — some page builders (e.g. custom template renderers)
 * print shortcodes as literal text instead of executing them. Catch any
 * unrendered [fcd_fundraising ...] in the final HTML and render it.
 * Frontend only; skips admin, feeds, REST and AJAX.
 * ---------------------------------------------------------------------- */
add_action( 'template_redirect', function () {
	if ( is_admin() || is_feed() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}
	ob_start( function ( $html ) {
		if ( false === strpos( $html, '[fcd_fundraising' ) ) {
			return $html;
		}
		return preg_replace_callback(
			'/\[fcd_fundraising[^\]]*\]/',
			function ( $m ) {
				return do_shortcode( $m[0] );
			},
			$html
		);
	} );
}, 1 );

function fcd_fr_shortcode( $atts ) {
	$atts = shortcode_atts( array(
		'template' => '',
		'amounts'  => '500,250,100,50',
		'title'    => 'Doe je mee?',
		'subtitle' => 'Hoeveel wil je geven?',
		'project'  => '',
	), $atts );

	$projects = fcd_fr_projects();
	if ( ! $projects ) {
		return current_user_can( 'manage_options' )
			? '<p><em>[fcd_fundraising]: voeg eerst projecten toe onder Fundraising in het dashboard.</em></p>'
			: '';
	}

	/*
	 * Fixed-project mode: [fcd_fundraising project="Name"] pins the form to one
	 * project (case-insensitive match against configured project names) and
	 * hides the dropdown. Invalid name → config error for admins, nothing for
	 * visitors.
	 */
	$fixed = null;
	if ( '' !== trim( (string) $atts['project'] ) ) {
		$lc     = function_exists( 'mb_strtolower' ) ? 'mb_strtolower' : 'strtolower';
		$needle = $lc( trim( (string) $atts['project'] ) );
		foreach ( $projects as $p ) {
			if ( $lc( trim( $p['name'] ) ) === $needle ) {
				$fixed = $p;
				break;
			}
		}
		if ( null === $fixed ) {
			if ( current_user_can( 'manage_options' ) ) {
				$names = implode( ', ', wp_list_pluck( $projects, 'name' ) );
				return '<p style="padding:14px 18px;background:#FDECEA;border:1px solid #F5C6CB;border-radius:8px;color:#842029;max-width:680px">'
					. '<strong>[fcd_fundraising]</strong>: project &ldquo;' . esc_html( $atts['project'] ) . '&rdquo; niet gevonden. '
					. 'Beschikbare projecten: ' . esc_html( $names ) . '. '
					. '(Deze melding is alleen zichtbaar voor beheerders.)</p>';
			}
			return '';
		}
		$projects = array( $fixed );
	}

	$collected = fcd_fr_collected();
	$tpl       = $atts['template'] && in_array( $atts['template'], array( 'pot', 'thermo', 'ring', 'bar' ), true )
		? $atts['template'] : fcd_fr_template();
	$amounts   = array_filter( array_map( 'floatval', explode( ',', $atts['amounts'] ) ) );
	$uid       = 'fcdfr_' . wp_generate_password( 6, false );
	$endpoint  = esc_url( admin_url( 'admin-ajax.php' ) );

	ob_start();
	?>
	<div class="fcdfr" id="<?php echo esc_attr( $uid ); ?>" data-tpl="<?php echo esc_attr( $tpl ); ?>">
	<style>
	.fcdfr{
		--fr-navy:#0A1633; --fr-navy-2:#13234B; --fr-steel:#5B74A8;
		--fr-green:#7DC242; --fr-green-d:#67A631;
		--fr-chip:#ffffff26; --fr-chip-b:#ffffff3d;
		font-family:inherit;
		/* break out of narrow builder columns: center on the viewport */
		position:relative;left:50%;transform:translateX(-50%);
		width:min(1080px,92vw);
		display:grid;gap:26px;
		grid-template-columns:repeat(auto-fit,minmax(min(100%,380px),1fr));
		align-items:stretch;
	}
	.fcdfr .fr-card,.fcdfr .fr-panel{border-radius:18px;box-shadow:0 12px 40px rgba(10,22,51,.12)}
	.fcdfr .fr-card{
		background:linear-gradient(160deg,var(--fr-navy),var(--fr-navy-2));
		padding:34px 32px 32px;color:#fff;
		display:flex;flex-direction:column;justify-content:center;
	}
	.fcdfr .fr-panel{
		background:var(--fr-steel);
		padding:42px 30px 34px;
		display:flex;flex-direction:column;align-items:center;justify-content:center;color:#fff;
		margin-top:0;
	}
	/* entrance reveal */
	.fcdfr .fr-card,.fcdfr .fr-panel{
		opacity:0;transform:translateY(26px);
		transition:opacity .7s cubic-bezier(.2,.7,.3,1),transform .7s cubic-bezier(.2,.7,.3,1),box-shadow .3s;
	}
	.fcdfr.fr-in .fr-card{opacity:1;transform:none}
	.fcdfr.fr-in .fr-panel{opacity:1;transform:none;transition-delay:.15s}
	/* goal reached badge */
	.fcdfr .fr-badge{
		display:none;margin-top:14px;background:#fff;color:var(--fr-navy);
		font-weight:800;font-size:14px;border-radius:999px;padding:8px 18px;
		animation:frpop .5s cubic-bezier(.2,.9,.3,1.4);
	}
	.fcdfr .fr-badge.on{display:inline-block}
	@keyframes frpop{from{transform:scale(.6);opacity:0}to{transform:scale(1);opacity:1}}
	/* pot bubbles */
	.fcdfr .fr-bub{animation:frbub 2.6s ease-in infinite;opacity:0}
	.fcdfr .fr-bub.b2{animation-delay:.9s;animation-duration:3.1s}
	.fcdfr .fr-bub.b3{animation-delay:1.7s;animation-duration:2.2s}
	@keyframes frbub{
		0%{transform:translateY(0);opacity:0}
		15%{opacity:.55}
		85%{opacity:.35}
		100%{transform:translateY(-70px);opacity:0}
	}
	/* falling coin */
	.fcdfr .fr-coin{opacity:0}
	.fcdfr .fr-coin.drop{animation:frcoin .6s cubic-bezier(.5,0,.9,1) forwards}
	@keyframes frcoin{
		0%{opacity:0;transform:translateY(0)}
		20%{opacity:1}
		100%{opacity:0;transform:translateY(36px)}
	}
	.fcdfr *{box-sizing:border-box}
	.fcdfr .fr-title{
		font-size:clamp(22px,2.4vw,28px);font-weight:800;letter-spacing:.6px;
		text-transform:uppercase;margin:0;color:#fff !important;line-height:1.1;
	}
	.fcdfr .fr-sub{font-size:15px;color:#C7D2EC !important;margin:8px 0 22px}
	.fcdfr .fr-projfixed{
		background:#ffffff1c;border:1px solid #ffffff30;border-radius:12px;
		padding:14px 16px;font-size:15.5px;font-weight:700;color:#fff;
		margin-bottom:16px;
	}
	.fcdfr select.fr-proj{
		width:100%;appearance:none;border:0;border-radius:12px;background:#fff;
		padding:15px 46px 15px 16px;font:inherit;font-size:15.5px;font-weight:600;color:var(--fr-navy);cursor:pointer;
		margin-bottom:16px;
		background-image:url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="14" height="9" viewBox="0 0 14 9"><path d="M1 1l6 6 6-6" fill="none" stroke="%230A1633" stroke-width="2" stroke-linecap="round"/></svg>');
		background-repeat:no-repeat;background-position:right 16px center;
	}
	.fcdfr .fr-chips{display:grid;grid-template-columns:repeat(auto-fit,minmax(90px,1fr));gap:10px}
	.fcdfr .fr-chip{
		background:var(--fr-chip);border:1px solid var(--fr-chip-b);border-radius:12px;
		color:#fff;font:inherit;font-size:15px;font-weight:700;padding:14px 0;cursor:pointer;transition:all .15s;
	}
	.fcdfr .fr-chip:hover{background:#ffffff3a;transform:translateY(-1px)}
	.fcdfr .fr-chip.sel{background:#fff;color:var(--fr-navy)}
	.fcdfr .fr-otherlbl{font-size:13.5px;font-weight:700;margin:16px 0 8px;letter-spacing:.3px;text-transform:uppercase}
	.fcdfr .fr-other{
		width:100%;border:0;border-radius:12px;padding:15px 16px;font:inherit;font-size:15.5px;color:var(--fr-navy);
	}
	.fcdfr .fr-other:focus{outline:2px solid var(--fr-green)}
	.fcdfr .fr-optlbl{font-size:12px;font-weight:600;margin:14px 0 6px;letter-spacing:.2px;color:#C7D2EC}
	.fcdfr .fr-email,.fcdfr .fr-name{
		width:100%;border:0;border-radius:12px;padding:13px 16px;font:inherit;font-size:14.5px;color:var(--fr-navy);
		margin-bottom:8px;
	}
	.fcdfr .fr-email:focus,.fcdfr .fr-name:focus{outline:2px solid var(--fr-green)}
	.fcdfr .fr-geef{
		margin-top:16px;width:100%;background:var(--fr-green);color:#fff;border:none;border-radius:12px;
		font:inherit;font-size:17px;font-weight:800;padding:16px;cursor:pointer;
		transition:background .2s,transform .12s,box-shadow .2s;
	}
	.fcdfr .fr-geef:hover{background:var(--fr-green-d);box-shadow:0 6px 18px rgba(125,194,66,.35)}
	.fcdfr .fr-geef:active{transform:scale(.985)}
	.fcdfr .fr-err{display:none;margin:10px 0 0;color:#FFB4B4;font-size:14px}

	.fcdfr .fr-caption{margin-top:20px;font-size:15.5px;color:#EAF0FB;text-align:center}
	.fcdfr .fr-pct{paint-order:stroke;stroke:#ffffff;stroke-width:4px;fill:var(--fr-navy);font-weight:800}

	.fcdfr .fr-liquid{transition:transform 1.3s cubic-bezier(.3,.7,.2,1)}
	.fcdfr .fr-wave{animation:frdrift 3.2s linear infinite}
	@keyframes frdrift{from{transform:translateX(0)}to{transform:translateX(-96px)}}
	.fcdfr .fr-ringtrack{stroke:#ffffff;fill:none;stroke-width:24;opacity:.95}
	.fcdfr .fr-ringbar{fill:none;stroke-width:24;stroke-linecap:round;transition:stroke-dashoffset 1.5s cubic-bezier(.3,.7,.2,1);filter:drop-shadow(0 2px 6px rgba(10,22,51,.35))}
	.fcdfr .fr-bartrack{width:min(420px,100%);height:24px;border-radius:999px;background:#fff;overflow:hidden}
	.fcdfr .fr-barfill{
		height:100%;width:0;border-radius:999px;position:relative;overflow:hidden;
		background:linear-gradient(90deg,var(--fr-navy),#24408A);
		transition:width 1.5s cubic-bezier(.3,.7,.2,1);
	}
	.fcdfr .fr-barfill::before{
		content:"";position:absolute;inset:0;
		background:repeating-linear-gradient(45deg,#ffffff1f 0 12px,transparent 12px 24px);
		animation:frstripe 1.4s linear infinite;
	}
	.fcdfr .fr-barfill::after{
		content:"";position:absolute;inset:0;
		background:linear-gradient(90deg,transparent,#ffffff45,transparent);
		animation:frshine 2.4s linear infinite;
	}
	@keyframes frstripe{from{background-position:0 0}to{background-position:34px 0}}
	@keyframes frshine{from{transform:translateX(-100%)}to{transform:translateX(100%)}}
	@media (prefers-reduced-motion:reduce){.fcdfr *{transition:none!important;animation:none!important}}
	</style>

	<div class="fr-card">
		<h3 class="fr-title"><?php echo esc_html( $atts['title'] ); ?></h3>
		<p class="fr-sub"><?php echo esc_html( $atts['subtitle'] ); ?></p>

		<?php if ( $fixed ) : ?>
			<div class="fr-projfixed"><?php echo esc_html( $fixed['name'] ); ?></div>
		<?php endif; ?>
		<select class="fr-proj" aria-label="Project"<?php echo $fixed ? ' style="display:none" aria-hidden="true" tabindex="-1"' : ''; ?>>
			<?php foreach ( $projects as $i => $p ) :
				$paid  = $collected[ $p['name'] ] ?? 0;
				$total = (int) round( $p['base'] * 100 ) + $paid; ?>
				<option value="<?php echo esc_attr( $i ); ?>"
					data-name="<?php echo esc_attr( $p['name'] ); ?>"
					data-goal="<?php echo esc_attr( (int) round( $p['goal'] * 100 ) ); ?>"
					data-raised="<?php echo esc_attr( $total ); ?>"><?php echo esc_html( $p['name'] ); ?></option>
			<?php endforeach; ?>
		</select>

		<div class="fr-chips">
			<?php foreach ( $amounts as $a ) : ?>
				<button type="button" class="fr-chip" data-amt="<?php echo esc_attr( $a ); ?>">€ <?php echo esc_html( rtrim( rtrim( number_format( $a, 2, ',', '' ), '0' ), ',' ) ); ?></button>
			<?php endforeach; ?>
		</div>

		<p class="fr-otherlbl">Ander bedrag</p>
		<input class="fr-other" type="number" min="1" step="0.01" placeholder="€ 0.00" aria-label="Ander bedrag">
		<p class="fr-optlbl">E-mail en naam (optioneel)</p>
		<input class="fr-email" type="email" placeholder="E-mailadres" aria-label="E-mailadres (optioneel)" autocomplete="email">
		<input class="fr-name" type="text" placeholder="Naam" aria-label="Naam (optioneel)" autocomplete="name">
		<button type="button" class="fr-geef">Geef</button>
		<p class="fr-err" role="alert"></p>
	</div>

	<div class="fr-panel">
		<div class="fr-stage" style="display:flex;align-items:center;justify-content:center;min-height:260px;width:100%"></div>
		<div class="fr-caption"></div>
		<span class="fr-badge">🎉 Doel behaald!</span>
	</div>

	<?php
	$html = ob_get_clean();

	/*
	 * Print the widget JS in the footer, outside builder-rendered content, so
	 * page builders (Etch etc.) that strip <script> tags from content can't
	 * remove it. If the footer has already run (e.g. shortcode rendered via
	 * the output-buffer fallback at shutdown), append the JS inline instead.
	 */
	ob_start();
	?>
	<script>
	(function(){
		var root=document.getElementById('<?php echo esc_js( $uid ); ?>');
		if(!root)return;
		var ENDPOINT='<?php echo $endpoint; ?>';
		var TPL=root.dataset.tpl;
		var sel=root.querySelector('.fr-proj');
		var stage=root.querySelector('.fr-stage');
		var cap=root.querySelector('.fr-caption');
		var err=root.querySelector('.fr-err');
		var geef=root.querySelector('.fr-geef');
		var eur=function(c){return '€ '+(Math.round(c/100)).toLocaleString('nl-NL');};

		function countTo(el,target,fmt){
			var s=performance.now(),D=1300;
			(function tick(now){
				var t=Math.min(1,(now-s)/D),e=1-Math.pow(1-t,3);
				el.textContent=fmt(target*e);
				if(t<1)requestAnimationFrame(tick);
			})(s);
		}
		var C=2*Math.PI*86;
		var TPLS={
			pot:function(){return{
				html:'<svg width="230" height="252" viewBox="0 0 210 235">'
					+'<defs><clipPath id="pc_<?php echo esc_js( $uid ); ?>"><path d="M55 52 h100 c3 0 5 2 5 5 v8 c22 16 34 40 34 68 c0 50 -40 86 -89 86 s-89 -36 -89 -86 c0 -28 12 -52 34 -68 v-8 c0 -3 2 -5 5 -5 z"/></clipPath></defs>'
					+'<path d="M55 52 h100 c3 0 5 2 5 5 v8 c22 16 34 40 34 68 c0 50 -40 86 -89 86 s-89 -36 -89 -86 c0 -28 12 -52 34 -68 v-8 c0 -3 2 -5 5 -5 z" fill="#ffffff" opacity=".95"/>'
					+'<g clip-path="url(#pc_<?php echo esc_js( $uid ); ?>)"><g class="fr-liquid" style="transform:translateY(170px)">'
					+'<path class="fr-wave" fill="var(--fr-navy)" d="M-96 8 q24 -8 48 0 t48 0 t48 0 t48 0 t48 0 t48 0 t48 0 v240 h-336 z"/>'
					+'<rect x="-96" y="14" width="500" height="240" fill="var(--fr-navy)"/>'
					+'<circle class="fr-bub" cx="85" cy="200" r="4" fill="#ffffff"/>'
					+'<circle class="fr-bub b2" cx="115" cy="205" r="3" fill="#ffffff"/>'
					+'<circle class="fr-bub b3" cx="130" cy="198" r="2.5" fill="#ffffff"/>'
					+'</g></g>'
					+'<rect x="85" y="38" width="40" height="7" rx="3.5" fill="var(--fr-navy)"/>'
					+'<circle class="fr-coin" cx="105" cy="18" r="9" fill="#F2B84B" stroke="#D69A2D" stroke-width="2"/>'
					+'<text class="fr-pct" x="105" y="142" text-anchor="middle" font-size="27">0%</text></svg>',
				animate:function(pct){
					var liq=stage.querySelector('.fr-liquid');
					var y=170-(150*pct/100);
					requestAnimationFrame(function(){requestAnimationFrame(function(){liq.style.transform='translateY('+y+'px)';});});
					countTo(stage.querySelector('.fr-pct'),pct,function(v){return Math.round(v)+'%';});
					var coin=stage.querySelector('.fr-coin');
					if(coin){coin.classList.remove('drop');void coin.getBoundingClientRect();coin.classList.add('drop');}
				}};},
			thermo:function(){return{
				html:'<svg width="150" height="255" viewBox="0 0 120 235">'
					+'<defs><clipPath id="tc_<?php echo esc_js( $uid ); ?>"><rect x="48" y="18" width="24" height="160" rx="12"/></clipPath></defs>'
					+'<rect x="48" y="18" width="24" height="160" rx="12" fill="#fff" opacity=".95"/>'
					+'<g clip-path="url(#tc_<?php echo esc_js( $uid ); ?>)"><rect class="fr-tfill" x="48" y="178" width="24" height="160" fill="var(--fr-navy)" style="transition:y 1.3s cubic-bezier(.3,.7,.2,1)"/></g>'
					+'<circle cx="60" cy="196" r="22" fill="var(--fr-navy)"/>'
					+'<text class="fr-tpct" x="60" y="197" text-anchor="middle" dominant-baseline="central" font-size="13" font-weight="800" fill="#fff">0%</text></svg>',
				animate:function(pct){
					var f=stage.querySelector('.fr-tfill');
					var y=178-160*pct/100;
					requestAnimationFrame(function(){requestAnimationFrame(function(){f.setAttribute('y',y);});});
					countTo(stage.querySelector('.fr-tpct'),pct,function(v){return Math.round(v)+'%';});
				}};},
			ring:function(){return{
				html:'<svg width="260" height="260" viewBox="0 0 230 230">'
					+'<defs><linearGradient id="rg_<?php echo esc_js( $uid ); ?>" x1="0" y1="0" x2="1" y2="1">'
					+'<stop offset="0" stop-color="var(--fr-navy)"/><stop offset="1" stop-color="#2E4C9B"/></linearGradient></defs>'
					+'<g transform="rotate(-90 115 115)">'
					+'<circle class="fr-ringtrack" cx="115" cy="115" r="86"/>'
					+'<circle class="fr-ringbar" cx="115" cy="115" r="86" stroke="url(#rg_<?php echo esc_js( $uid ); ?>)" stroke-dasharray="'+C+'" stroke-dashoffset="'+C+'"/></g>'
					+'<text class="fr-rtxt" x="115" y="115" text-anchor="middle" dominant-baseline="central" font-size="26" font-weight="800" fill="var(--fr-navy)">€ 0</text></svg>',
				animate:function(pct,raised){
					var bar=stage.querySelector('.fr-ringbar');
					requestAnimationFrame(function(){requestAnimationFrame(function(){bar.style.strokeDashoffset=C*(1-pct/100);});});
					countTo(stage.querySelector('.fr-rtxt'),raised,function(v){return eur(v);});
				}};},
			bar:function(){return{
				html:'<div style="width:100%;display:flex;flex-direction:column;align-items:center">'
					+'<div class="fr-bartrack"><div class="fr-barfill"></div></div>'
					+'<div class="fr-btxt" style="margin-top:12px;font-size:22px;font-weight:800">0%</div></div>',
				animate:function(pct){
					var f=stage.querySelector('.fr-barfill');
					requestAnimationFrame(function(){requestAnimationFrame(function(){f.style.width=pct+'%';});});
					countTo(stage.querySelector('.fr-btxt'),pct,function(v){return Math.round(v)+'%';});
				}};}
		};

		function render(){
			var o=sel.options[sel.selectedIndex];
			var raised=+o.dataset.raised, goal=+o.dataset.goal;
			var pct=Math.min(100,raised/goal*100);
			var t=TPLS[TPL]();
			stage.innerHTML=t.html;
			t.animate(pct,raised);
			cap.textContent=eur(raised)+',- van de '+eur(goal)+' ingezameld.';
			var badge=root.querySelector('.fr-badge');
			if(badge){badge.classList.toggle('on',pct>=100);}
		}
		sel.addEventListener('change',render);

		/* reveal + animate when scrolled into view (once) */
		var revealed=false;
		function reveal(){
			if(revealed)return;revealed=true;
			root.classList.add('fr-in');
			render();
		}
		if('IntersectionObserver' in window){
			var io=new IntersectionObserver(function(es){
				es.forEach(function(e){if(e.isIntersecting){reveal();io.disconnect();}});
			},{threshold:.25});
			io.observe(root);
			/* already in view on load */
			var r=root.getBoundingClientRect();
			if(r.top<window.innerHeight&&r.bottom>0){reveal();}
		}else{
			reveal();
		}

		root.querySelectorAll('.fr-chip').forEach(function(c){
			c.addEventListener('click',function(){
				root.querySelectorAll('.fr-chip').forEach(function(x){x.classList.remove('sel');});
				c.classList.add('sel');
				root.querySelector('.fr-other').value='';
				err.style.display='none';
			});
		});
		root.querySelector('.fr-other').addEventListener('input',function(){
			root.querySelectorAll('.fr-chip').forEach(function(x){x.classList.remove('sel');});
			err.style.display='none';
		});

		function crid(){
			if(window.crypto&&crypto.randomUUID)return crypto.randomUUID();
			return 'fcdfr-'+Date.now()+'-'+String(Math.random()).slice(2,10);
		}
		geef.addEventListener('click',function(){
			var selChip=root.querySelector('.fr-chip.sel');
			var other=root.querySelector('.fr-other').value;
			var amt=other||(selChip&&selChip.dataset.amt);
			if(!amt||parseFloat(amt)<=0){err.textContent='Kies eerst een bedrag.';err.style.display='block';return;}
			var o=sel.options[sel.selectedIndex];
			geef.disabled=true;var orig=geef.textContent;geef.textContent='U wordt doorverwezen…';
			var body=new URLSearchParams({
				action:'fcd_fr_pay',
				pwyw_amount:String(amt),
				client_request_id:crid(),
				fcd_project:o.dataset.name,
				page_url:window.location.href,
				donor_email:root.querySelector('.fr-email').value,
				donor_name:root.querySelector('.fr-name').value
			});
			fetch(ENDPOINT,{
				method:'POST',
				headers:{'Content-Type':'application/x-www-form-urlencoded'},
				body:body.toString(),
				credentials:'same-origin'
			}).then(function(r){return r.json().then(function(d){return{ok:r.ok,d:d};});})
			.then(function(res){
				var url=res.d&&res.d.data&&res.d.data.checkout_url;
				if(res.ok&&res.d.success&&url){window.location.replace(url);return;}
				throw new Error((res.d&&res.d.data&&res.d.data.message)||'Er ging iets mis.');
			})
			.catch(function(e){
				err.textContent=(e&&e.message)||'Er ging iets mis. Probeer het opnieuw.';
				err.style.display='block';
				geef.disabled=false;geef.textContent=orig;
			});
		});
	})();
	</script>
	<?php
	$js = ob_get_clean();

	if ( did_action( 'wp_footer' ) ) {
		// Rendered after the footer (output-buffer fallback path): inline is fine.
		return $html . '</div>' . $js;
	}
	add_action( 'wp_footer', function () use ( $js ) {
		echo $js; // phpcs:ignore WordPress.Security.EscapeOutput
	}, 50 );
	return $html . '</div>';
}
