<?php
/**
 * Plugin Name:       THW Dienste Manager
 * Plugin URI:        thw-muenchen-mitte.de
 * Description:       Ein Plugin zur Verwaltung von THW Diensten, Helfern und Anwesenheiten.
 * Version:           1.0.0
 * Author:            Matthias Verwold
 * Text Domain:       thw-dienst-manager
 * Domain Path:       /languages
 */

// Direktzugriff verhindern
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Frontend-Funktionalität laden
require_once plugin_dir_path( __FILE__ ) . 'dienstdetails.php';
require_once plugin_dir_path( __FILE__ ) . 'Abwesenheiten.php';
require_once plugin_dir_path( __FILE__ ) . 'dienstbeteiligung.php';
require_once plugin_dir_path( __FILE__ ) . 'stammdaten.php';
require_once plugin_dir_path( __FILE__ ) . 'auswertungen.php';

/**
 * Haupt-Shortcode [thw_frontend]
 * Zeigt ein Menü und lädt je nach Auswahl die Dienst-Details oder Abwesenheiten.
 */
function thw_dm_main_frontend_shortcode() {
	// Zugriffsschutz: Nur Admins oder Rolle 'fuehrung'
	$user = wp_get_current_user();
	$allowed_roles = array( 'administrator', 'fuehrung' );
	
	if ( ! is_user_logged_in() || ! array_intersect( $allowed_roles, (array) $user->roles ) ) {
		return '<div class="thw-message error" style="background:#f8d7da; color:#721c24; padding:15px; border:1px solid #f5c6cb; border-radius:5px;">' . esc_html__( 'Zugriff verweigert. Dieser Bereich ist nur für Führungskräfte zugänglich.', 'thw-dienst-manager' ) . '</div>';
	}

	$is_admin = current_user_can( 'administrator' );

	$view = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'absences';
	
	// URLs für die Tabs
	$url_services = add_query_arg( 'view', 'services', get_permalink() );
	$url_absences = add_query_arg( 'view', 'absences', get_permalink() );
	$url_participation = add_query_arg( 'view', 'participation', get_permalink() );
	$url_stammdaten = add_query_arg( 'view', 'stammdaten', get_permalink() );
	$url_evaluation = add_query_arg( 'view', 'evaluation', get_permalink() );
	
	ob_start();
	?>
	<style>
		.thw-frontend-container { font-family: sans-serif; max-width: 100%; }
		.thw-tabs { display: flex; flex-wrap: wrap; border-bottom: 1px solid #ddd; margin-bottom: 20px; gap: 5px; }
		.thw-tab-item { 
			text-decoration: none; 
			padding: 12px 15px; 
			border: 1px solid #ddd; 
			border-bottom: none; 
			background: #f9f9f9; 
			color: #555; 
			font-weight: bold; 
			border-radius: 4px 4px 0 0;
			margin-bottom: -1px;
			flex: 0 1 auto;
		}
		.thw-tab-item.active { background: #fff; color: #003399; border-top: 3px solid #003399; border-bottom: 1px solid #fff; }
		.thw-tab-item:hover { background: #eee; }

		/* Globale Frontend Stile */
		.thw-frontend-wrapper { max-width: 1000px; margin: 0 auto; }
		.thw-card { background: #f9f9f9; border: 1px solid #ddd; padding: 20px; margin-bottom: 20px; border-radius: 5px; }
		.thw-card select { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 3px; background-color: #fff; }
		.thw-btn { background: #003399; color: #fff; border: none; padding: 8px 15px; cursor: pointer; border-radius: 3px; text-decoration: none; display: inline-block; }
		.thw-btn:hover { background: #002266; color: #fff; }
		.thw-message { padding: 15px; margin-bottom: 20px; border-radius: 5px; border: 1px solid; }
		.thw-message.success { background-color: #d4edda; color: #155724; border-color: #c3e6cb; }
		.thw-message.error { background-color: #f8d7da; color: #721c24; border-color: #f5c6cb; }
		
		.thw-flex { display: flex; gap: 15px; flex-wrap: wrap; }
		.thw-col { flex: 1; min-width: 200px; }
		.thw-card label { font-weight: bold; display: block; margin-bottom: 5px; }
		@media (max-width: 600px) { .thw-col { flex: 1 1 100%; } }

		/* Mobile Optimierung für Tabs */
		@media (max-width: 768px) {
			.thw-tabs { display: block; border-bottom: none; gap: 0; }
			.thw-tab-item { display: block; margin-bottom: 5px; border-bottom: 1px solid #ddd; border-radius: 4px; text-align: center; }
			.thw-tab-item.active { border-bottom: 1px solid #ddd; border-left: 5px solid #003399; border-top: 1px solid #ddd; }
		}
	</style>
	<div class="thw-frontend-container">
		<div class="thw-tabs">
			<a href="<?php echo esc_url( $url_absences ); ?>" class="thw-tab-item <?php echo $view === 'absences' ? 'active' : ''; ?>">Abwesenheiten</a>
			<a href="<?php echo esc_url( $url_services ); ?>" class="thw-tab-item <?php echo $view === 'services' ? 'active' : ''; ?>">Dienst Ausbildungsthemen</a>
			<a href="<?php echo esc_url( $url_participation ); ?>" class="thw-tab-item <?php echo $view === 'participation' ? 'active' : ''; ?>">Dienstbeteiligung</a>
			<a href="<?php echo esc_url( $url_evaluation ); ?>" class="thw-tab-item <?php echo $view === 'evaluation' ? 'active' : ''; ?>">Auswertungen</a>
			<?php if ( $is_admin ) : ?>
				<a href="<?php echo esc_url( $url_stammdaten ); ?>" class="thw-tab-item <?php echo $view === 'stammdaten' ? 'active' : ''; ?>">Stammdaten</a>
			<?php endif; ?>
		</div>
		
		<div class="thw-tab-content">
			<?php
			if ( $view === 'absences' ) {
				echo thw_dm_absences_shortcode();
			} elseif ( $view === 'participation' ) {
				echo thw_dm_participation_shortcode();
			} elseif ( $view === 'evaluation' ) {
				echo thw_dm_evaluation_shortcode();
			} elseif ( $view === 'stammdaten' && $is_admin ) {
				echo thw_dm_stammdaten_shortcode();
			} else {
				echo thw_dm_frontend_shortcode();
			}
			?>
		</div>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'thw_frontend', 'thw_dm_main_frontend_shortcode' );

/**
 * Erstellt die Datenbanktabelle bei Aktivierung des Plugins.
 */
function thw_dm_install() {
	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate();

	// Tabelle für Einheiten
	$table_units = $wpdb->prefix . 'thw_units';
	$sql_units = "CREATE TABLE $table_units (
		id mediumint(9) NOT NULL AUTO_INCREMENT,
		zug tinytext NOT NULL,
		bezeichnung tinytext NOT NULL,
		PRIMARY KEY  (id)
	) $charset_collate;";

	// Tabelle für Dienste
	$table_services = $wpdb->prefix . 'thw_services';
	$sql_services = "CREATE TABLE $table_services (
		id mediumint(9) NOT NULL AUTO_INCREMENT,
		name tinytext NOT NULL,
		service_date date NOT NULL,
		unit_ids text NOT NULL,
		attendance longtext DEFAULT '' NOT NULL,
		status varchar(20) DEFAULT 'open' NOT NULL,
		PRIMARY KEY  (id)
	) $charset_collate;";

	// Tabelle für Dienst-Details (Ausbildungsinhalte)
	$table_details = $wpdb->prefix . 'thw_service_details';
	$sql_details = "CREATE TABLE $table_details (
		id mediumint(9) NOT NULL AUTO_INCREMENT,
		service_id mediumint(9) NOT NULL,
		unit_id mediumint(9) NOT NULL,
		topic text NOT NULL,
		duration int NOT NULL,
		goal text NOT NULL,
		section text DEFAULT '' NOT NULL,
		PRIMARY KEY  (id)
	) $charset_collate;";

	// Tabelle für Abwesenheiten
	$table_absences = $wpdb->prefix . 'thw_absences';
	$sql_absences = "CREATE TABLE $table_absences (
		id mediumint(9) NOT NULL AUTO_INCREMENT,
		user_id bigint(20) NOT NULL,
		start_date date NOT NULL,
		end_date date NOT NULL,
		reason text NOT NULL,
		PRIMARY KEY  (id)
	) $charset_collate;";

	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
	dbDelta( $sql_units );
	dbDelta( $sql_services );
	dbDelta( $sql_details );
	dbDelta( $sql_absences );

	// Rolle "Führung" anlegen, falls noch nicht vorhanden
	add_role( 'fuehrer', __( 'Führungskraft', 'thw-dienst-manager' ), array( 'read' => true ) );
}
register_activation_hook( __FILE__, 'thw_dm_install' );

/**
 * Fügt das Menü "THW Manager" mit Untermenüs hinzu.
 */
function thw_dm_add_admin_menu() {
	add_menu_page(
		__( 'THW Manager', 'thw-dienst-manager' ),
		__( 'THW Manager', 'thw-dienst-manager' ),
		'manage_options',
		'thw-units',
		'thw_dm_units_page_html',
		'dashicons-groups',
		20
	);

	// Untermenü: Einheiten (Standard)
	add_submenu_page(
		'thw-units',
		__( 'Einheiten', 'thw-dienst-manager' ),
		__( 'Einheiten', 'thw-dienst-manager' ),
		'manage_options',
		'thw-units',
		'thw_dm_units_page_html'
	);

	// Untermenü: Dienste
	add_submenu_page(
		'thw-units',
		__( 'Dienste', 'thw-dienst-manager' ),
		__( 'Dienste', 'thw-dienst-manager' ),
		'manage_options',
		'thw-services',
		'thw_dm_services_page_html'
	);

	// Untermenü: Benutzer Zuordnung
	add_submenu_page(
		'thw-units',
		__( 'Benutzer Zuordnung', 'thw-dienst-manager' ),
		__( 'Benutzer Zuordnung', 'thw-dienst-manager' ),
		'manage_options',
		'thw-users',
		'thw_dm_users_page_html'
	);
}
add_action( 'admin_menu', 'thw_dm_add_admin_menu' );

/**
 * Zeigt die Verwaltungsseite für Einheiten an.
 */
function thw_dm_units_page_html() {
	global $wpdb;
	$table_name = $wpdb->prefix . 'thw_units';

	// --- LOGIC: Löschen ---
	if ( isset( $_GET['action'], $_GET['id'], $_GET['_wpnonce'] ) && $_GET['action'] === 'delete' ) {
		if ( wp_verify_nonce( $_GET['_wpnonce'], 'delete_unit_' . $_GET['id'] ) ) {
			$wpdb->delete( $table_name, array( 'id' => intval( $_GET['id'] ) ) );
			echo '<div class="updated"><p>' . esc_html__( 'Eintrag gelöscht.', 'thw-dienst-manager' ) . '</p></div>';
		}
	}

	// --- LOGIC: Speichern (Neu oder Bearbeiten) ---
	if ( isset( $_POST['thw_dm_submit'] ) && check_admin_referer( 'thw_dm_save_unit' ) ) {
		$zug = sanitize_text_field( $_POST['zug'] );
		$bezeichnung = sanitize_text_field( $_POST['bezeichnung'] );
		$id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;

		$result = false;
		if ( $id > 0 ) {
			// Update
			$result = $wpdb->update( $table_name, array( 'zug' => $zug, 'bezeichnung' => $bezeichnung ), array( 'id' => $id ) );
		} else {
			// Insert
			$result = $wpdb->insert( $table_name, array( 'zug' => $zug, 'bezeichnung' => $bezeichnung ) );
		}

		if ( false === $result ) {
			echo '<div class="notice notice-error"><p>' . sprintf( __( 'Fehler beim Speichern: %s', 'thw-dienst-manager' ), $wpdb->last_error ) . '</p><p><strong>Lösung:</strong> Bitte deaktiviere das Plugin einmal und aktiviere es wieder.</p></div>';
		} else {
			echo '<div class="updated"><p>' . esc_html__( 'Gespeichert.', 'thw-dienst-manager' ) . '</p></div>';
		}
	}

	// --- DATEN LADEN ---
	$einheiten = $wpdb->get_results( "SELECT * FROM $table_name ORDER BY zug ASC, bezeichnung ASC" );

	// Edit-Modus prüfen
	$edit_entry = null;
	if ( isset( $_GET['action'], $_GET['id'] ) && $_GET['action'] === 'edit' ) {
		$edit_entry = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE id = %d", intval( $_GET['id'] ) ) );
	}

	// --- VIEW ---
	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'Einheiten Verwaltung', 'thw-dienst-manager' ); ?></h1>
		
		<!-- FORMULAR BEREICH -->
		<div class="card" style="max-width: 100%; padding: 20px; margin-bottom: 20px;">
			<h2><?php echo $edit_entry ? 'Einheit bearbeiten' : 'Neue Einheit erstellen'; ?></h2>
			<form method="post" action="admin.php?page=thw-units">
				<?php wp_nonce_field( 'thw_dm_save_unit' ); ?>
				<?php if ( $edit_entry ) : ?>
					<input type="hidden" name="id" value="<?php echo esc_attr( $edit_entry->id ); ?>">
				<?php endif; ?>

				<table class="form-table">
					<tr>
						<th><label for="zug">Zug</label></th>
						<td><input type="text" name="zug" id="zug" class="regular-text" value="<?php echo $edit_entry ? esc_attr( $edit_entry->zug ) : ''; ?>" required placeholder="z.B. 1. Technischer Zug"></td>
					</tr>
					<tr>
						<th><label for="bezeichnung">Bezeichnung</label></th>
						<td><input type="text" name="bezeichnung" id="bezeichnung" class="regular-text" value="<?php echo $edit_entry ? esc_attr( $edit_entry->bezeichnung ) : ''; ?>" required placeholder="z.B. Bergungsgruppe"></td>
					</tr>
				</table>
				
				<p class="submit">
					<input type="submit" name="thw_dm_submit" id="submit" class="button button-primary" value="<?php echo $edit_entry ? 'Änderungen speichern' : 'Einheit erstellen'; ?>">
					<?php if ( $edit_entry ) : ?>
						<a href="admin.php?page=thw-units" class="button">Abbrechen</a>
					<?php endif; ?>
				</p>
			</form>
		</div>

		<hr>

		<h3>Vorhandene Einheiten</h3>
		<table class="widefat fixed striped">
			<thead>
				<tr>
					<th>Zug</th>
					<th>Bezeichnung</th>
					<th style="width: 120px;">Aktionen</th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $einheiten ) ) : ?>
					<tr><td colspan="3">Keine Einheiten angelegt.</td></tr>
				<?php else : ?>
					<?php foreach ( $einheiten as $einheit ) : ?>
						<tr>
							<td><?php echo esc_html( $einheit->zug ); ?></td>
							<td><strong><?php echo esc_html( $einheit->bezeichnung ); ?></strong></td>
							<td>
								<a href="admin.php?page=thw-units&action=edit&id=<?php echo $einheit->id; ?>">Bearbeiten</a> | 
								<a href="<?php echo wp_nonce_url( 'admin.php?page=thw-units&action=delete&id=' . $einheit->id, 'delete_unit_' . $einheit->id ); ?>" style="color: #a00;" onclick="return confirm('Einheit wirklich löschen?');">Löschen</a>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>
	<?php
}

/**
 * Zeigt die Verwaltungsseite für Benutzer-Zuordnungen an.
 */
function thw_dm_users_page_html() {
	global $wpdb;
	$table_units = $wpdb->prefix . 'thw_units';

	// --- LOGIC: Speichern ---
	if ( isset( $_POST['thw_dm_save_users'] ) && check_admin_referer( 'thw_dm_save_users_nonce' ) ) {
		if ( isset( $_POST['user_unit'] ) && is_array( $_POST['user_unit'] ) ) {
			foreach ( $_POST['user_unit'] as $user_id => $unit_id ) {
				update_user_meta( $user_id, 'thw_unit_id', intval( $unit_id ) );
			}
			echo '<div class="updated"><p>' . esc_html__( 'Benutzerzuordnungen gespeichert.', 'thw-dienst-manager' ) . '</p></div>';
		}
	}

	// --- DATEN LADEN ---
	$units = $wpdb->get_results( "SELECT * FROM $table_units ORDER BY zug ASC, bezeichnung ASC" );
	$users = get_users();

	// Benutzer nach Nachname sortieren
	usort( $users, function( $a, $b ) {
		$name_a = ! empty( $a->last_name ) ? $a->last_name : $a->display_name;
		$name_b = ! empty( $b->last_name ) ? $b->last_name : $b->display_name;
		$res = strcasecmp( $name_a, $name_b );
		if ( $res == 0 ) {
			$first_a = ! empty( $a->first_name ) ? $a->first_name : '';
			$first_b = ! empty( $b->first_name ) ? $b->first_name : '';
			return strcasecmp( $first_a, $first_b );
		}
		return $res;
	} );

	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'Benutzer Einheiten zuordnen', 'thw-dienst-manager' ); ?></h1>
		<form method="post">
			<?php wp_nonce_field( 'thw_dm_save_users_nonce' ); ?>
			<input type="hidden" name="thw_dm_save_users" value="1">
			
			<table class="widefat fixed striped">
				<thead>
					<tr>
						<th>Benutzername</th>
						<th>Anzeigename</th>
						<th>Zugeordnete Einheit</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $users as $user ) : 
						$current_unit_id = get_user_meta( $user->ID, 'thw_unit_id', true );
						
						$lastname = $user->last_name;
						$firstname = $user->first_name;
						$fullname = $user->display_name;
						if ( ! empty( $lastname ) ) {
							$fullname = $lastname . ( ! empty( $firstname ) ? ', ' . $firstname : '' );
						}
					?>
						<tr>
							<td><?php echo esc_html( $user->user_login ); ?></td>
							<td><?php echo esc_html( $fullname ); ?></td>
							<td>
								<select name="user_unit[<?php echo $user->ID; ?>]">
									<option value="0"><?php echo esc_html__( '-- Keine --', 'thw-dienst-manager' ); ?></option>
									<?php foreach ( $units as $unit ) : ?>
										<option value="<?php echo $unit->id; ?>" <?php selected( $current_unit_id, $unit->id ); ?>>
											<?php echo esc_html( $unit->zug . ' - ' . $unit->bezeichnung ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="submit"><input type="submit" class="button button-primary" value="Speichern"></p>
		</form>
	</div>
	<?php
}

/**
 * Zeigt die Verwaltungsseite für Dienste an.
 */
function thw_dm_services_page_html() {
	global $wpdb;
	$table_services = $wpdb->prefix . 'thw_services';
	$table_units    = $wpdb->prefix . 'thw_units';

	// --- LOGIC: Löschen ---
	if ( isset( $_GET['action'], $_GET['id'], $_GET['_wpnonce'] ) && $_GET['action'] === 'delete' ) {
		if ( wp_verify_nonce( $_GET['_wpnonce'], 'delete_service_' . $_GET['id'] ) ) {
			$wpdb->delete( $table_services, array( 'id' => intval( $_GET['id'] ) ) );
			echo '<div class="updated"><p>' . esc_html__( 'Dienst gelöscht.', 'thw-dienst-manager' ) . '</p></div>';
		}
	}

	// --- LOGIC: Speichern ---
	if ( isset( $_POST['thw_dm_save_service'] ) && check_admin_referer( 'thw_dm_save_service_nonce' ) ) {
		$name = sanitize_text_field( $_POST['name'] );
		$date = sanitize_text_field( $_POST['service_date'] );
		$unit_ids = isset( $_POST['unit_ids'] ) ? array_map( 'intval', $_POST['unit_ids'] ) : array();
		$serialized_units = maybe_serialize( $unit_ids );
		
		$id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;

		$data = array(
			'name' => $name,
			'service_date' => $date,
			'unit_ids' => $serialized_units
		);

		if ( $id > 0 ) {
			$wpdb->update( $table_services, $data, array( 'id' => $id ) );
			echo '<div class="updated"><p>' . esc_html__( 'Dienst aktualisiert.', 'thw-dienst-manager' ) . '</p></div>';
		} else {
			$wpdb->insert( $table_services, $data );
			echo '<div class="updated"><p>' . esc_html__( 'Dienst erstellt.', 'thw-dienst-manager' ) . '</p></div>';
		}
	}

	// --- DATEN LADEN ---
	$services = $wpdb->get_results( "SELECT * FROM $table_services ORDER BY service_date DESC" );
	$units    = $wpdb->get_results( "SELECT * FROM $table_units ORDER BY zug ASC, bezeichnung ASC" );
	
	// Map für Einheitennamen
	$unit_map = array();
	foreach ( $units as $u ) { $unit_map[ $u->id ] = $u->zug . ' - ' . $u->bezeichnung; }

	// Edit-Modus
	$edit_entry = null;
	$selected_units = array();
	if ( isset( $_GET['action'], $_GET['id'] ) && $_GET['action'] === 'edit' ) {
		$edit_entry = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_services WHERE id = %d", intval( $_GET['id'] ) ) );
		if ( $edit_entry ) {
			$selected_units = maybe_unserialize( $edit_entry->unit_ids );
			if ( ! is_array( $selected_units ) ) $selected_units = array();
		}
	}
	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'Dienste verwalten', 'thw-dienst-manager' ); ?></h1>
		
		<div class="card" style="max-width: 100%; padding: 20px; margin-bottom: 20px;">
			<h2><?php echo $edit_entry ? 'Dienst bearbeiten' : 'Neuen Dienst anlegen'; ?></h2>
			<form method="post" action="admin.php?page=thw-services">
				<?php wp_nonce_field( 'thw_dm_save_service_nonce' ); ?>
				<input type="hidden" name="thw_dm_save_service" value="1">
				<?php if ( $edit_entry ) : ?><input type="hidden" name="id" value="<?php echo $edit_entry->id; ?>"><?php endif; ?>

				<table class="form-table">
					<tr><th><label for="name">Bezeichnung</label></th><td><input type="text" name="name" id="name" class="regular-text" required value="<?php echo $edit_entry ? esc_attr( $edit_entry->name ) : ''; ?>"></td></tr>
					<tr><th><label for="service_date">Datum</label></th><td><input type="date" name="service_date" id="service_date" required value="<?php echo $edit_entry ? esc_attr( $edit_entry->service_date ) : ''; ?>"></td></tr>
					<tr><th>Beteiligte Einheiten</th><td>
						<div style="max-height: 200px; overflow-y: auto; border: 1px solid #ccc; padding: 10px; background: #fff;">
							<?php foreach ( $units as $unit ) : ?>
								<label style="display:block; margin-bottom: 4px;">
									<input type="checkbox" name="unit_ids[]" value="<?php echo $unit->id; ?>" <?php checked( in_array( $unit->id, $selected_units ) ); ?>>
									<?php echo esc_html( $unit->zug . ' - ' . $unit->bezeichnung ); ?>
								</label>
							<?php endforeach; ?>
						</div>
					</td></tr>
				</table>
				<p class="submit"><input type="submit" class="button button-primary" value="Speichern"> <?php if($edit_entry): ?><a href="admin.php?page=thw-services" class="button">Abbrechen</a><?php endif; ?></p>
			</form>
		</div>

		<table class="widefat fixed striped">
			<thead><tr><th>Datum</th><th>Bezeichnung</th><th>Einheiten</th><th>Aktionen</th></tr></thead>
			<tbody>
				<?php if ( empty( $services ) ) : ?><tr><td colspan="4">Keine Dienste gefunden.</td></tr><?php else : ?>
					<?php foreach ( $services as $service ) : 
						$s_units = maybe_unserialize( $service->unit_ids );
						if ( ! is_array( $s_units ) ) $s_units = array();
						$names = array();
						foreach ( $s_units as $uid ) { if ( isset( $unit_map[$uid] ) ) $names[] = $unit_map[$uid]; }
					?>
					<tr>
						<td><?php echo date_i18n( get_option( 'date_format' ), strtotime( $service->service_date ) ); ?></td>
						<td><strong><?php echo esc_html( $service->name ); ?></strong></td>
						<td><?php echo esc_html( implode( ', ', $names ) ); ?></td>
						<td><a href="admin.php?page=thw-services&action=edit&id=<?php echo $service->id; ?>">Bearbeiten</a> | <a href="<?php echo wp_nonce_url( 'admin.php?page=thw-services&action=delete&id=' . $service->id, 'delete_service_' . $service->id ); ?>" style="color: #a00;" onclick="return confirm('Löschen?');">Löschen</a></td>
					</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>
	<?php
}
