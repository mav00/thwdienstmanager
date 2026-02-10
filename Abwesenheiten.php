<?php
// Direktzugriff verhindern
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode [thw_absences]
 * Frontend-Verwaltung für Abwesenheiten.
 */
function thw_dm_absences_shortcode() {
	global $wpdb;
	$table_absences = $wpdb->prefix . 'thw_absences';
	$table_units    = $wpdb->prefix . 'thw_units';

	// Basis-URL für diesen Tab
	$page_url = add_query_arg( 'view', 'absences', get_permalink() );

	ob_start();

	// Basis-URL für Formulare
	$base_url = remove_query_arg( array( 'action', 'abs_id', '_wpnonce', 'thw_msg' ) );

	// --- LOGIK: Speichern / Löschen ---
	$msg = '';

	// Speichern
	if ( isset( $_POST['thw_save_absence'] ) && check_admin_referer( 'thw_save_absence_nonce' ) ) {
		$user_id = intval( $_POST['selected_user'] );
		$start   = sanitize_text_field( $_POST['start_date'] );
		$end     = sanitize_text_field( $_POST['end_date'] );
		$reason  = sanitize_text_field( $_POST['reason'] );
		$abs_id  = intval( $_POST['abs_id'] );

		if ( $user_id && $start && $end ) {
			if ( $abs_id > 0 ) {
				$wpdb->update( $table_absences, array( 'start_date' => $start, 'end_date' => $end, 'reason' => $reason ), array( 'id' => $abs_id ) );
				$msg = '<div class="thw-msg success">Abwesenheit aktualisiert.</div>';
			} else {
				$wpdb->insert( $table_absences, array( 'user_id' => $user_id, 'start_date' => $start, 'end_date' => $end, 'reason' => $reason ) );
				$msg = '<div class="thw-msg success">Abwesenheit eingetragen.</div>';
			}
		}
	}

	// Löschen
	if ( isset( $_GET['action'] ) && $_GET['action'] === 'delete' && isset( $_GET['abs_id'] ) ) {
		if ( wp_verify_nonce( $_GET['_wpnonce'], 'delete_absence_' . $_GET['abs_id'] ) ) {
			$wpdb->delete( $table_absences, array( 'id' => intval( $_GET['abs_id'] ) ) );
			$msg = '<div class="thw-msg success">Eintrag gelöscht.</div>';
		}
	}

	// --- DATEN LADEN ---
	// Filter-Status
	$filter_zug = isset( $_REQUEST['filter_zug'] ) ? sanitize_text_field( $_REQUEST['filter_zug'] ) : '';
	$filter_unit_id = isset( $_REQUEST['filter_unit'] ) ? intval( $_REQUEST['filter_unit'] ) : 0;
	$selected_user_id = isset( $_REQUEST['selected_user'] ) ? intval( $_REQUEST['selected_user'] ) : 0;
	$filter_submitted = isset( $_REQUEST['filter_submitted'] );
	$hide_past = $filter_submitted ? isset( $_REQUEST['hide_past'] ) : true; // Default: Vergangene ausblenden

	// Züge laden
	$zuege = $wpdb->get_col( "SELECT DISTINCT zug FROM $table_units ORDER BY zug ASC" );

	// Einheiten laden (ggf. nach Zug gefiltert)
	$sql_units = "SELECT * FROM $table_units";
	if ( ! empty( $filter_zug ) ) {
		$sql_units .= $wpdb->prepare( " WHERE zug = %s", $filter_zug );
	}
	$sql_units .= " ORDER BY zug ASC, bezeichnung ASC";
	$units = $wpdb->get_results( $sql_units );

	// Benutzer laden (ggf. gefiltert)
	$user_args = array();
	if ( $filter_unit_id > 0 ) {
		$user_args['meta_key']   = 'thw_unit_id';
		$user_args['meta_value'] = $filter_unit_id;
	} elseif ( ! empty( $filter_zug ) ) {
		// Alle Einheiten dieses Zugs holen
		$zug_unit_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $table_units WHERE zug = %s", $filter_zug ) );
		if ( ! empty( $zug_unit_ids ) ) {
			$user_args['meta_key']     = 'thw_unit_id';
			$user_args['meta_value']   = $zug_unit_ids;
			$user_args['meta_compare'] = 'IN';
		} else {
			$user_args['include'] = array( 0 ); // Keine Ergebnisse
		}
	}
	$users = get_users( $user_args );
	
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

	// Map User ID -> Name erstellen (für die Anzeige in der Liste)
	$user_names = array();
	foreach ( $users as $u ) {
		$lastname = $u->last_name;
		$firstname = $u->first_name;
		$fullname = ! empty( $lastname ) ? $lastname . ( ! empty( $firstname ) ? ', ' . $firstname : '' ) : $u->display_name;
		$user_names[ $u->ID ] = $fullname;
	}

	// Bearbeitungs-Modus prüfen
	$edit_entry = null;
	if ( isset( $_GET['action'] ) && $_GET['action'] === 'edit' && isset( $_GET['abs_id'] ) ) {
		$edit_entry = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_absences WHERE id = %d", intval( $_GET['abs_id'] ) ) );
		if ( $edit_entry ) {
			// Sicherstellen, dass wir beim richtigen User bleiben
			$selected_user_id = $edit_entry->user_id;
		}
	}

	// Basis-Argumente für Links (damit Filter erhalten bleiben)
	$link_args = array(
		'selected_user' => $selected_user_id,
		'filter_unit'   => $filter_unit_id,
		'filter_zug'    => $filter_zug,
		'filter_submitted' => '1',
	);
	if ( $hide_past ) { $link_args['hide_past'] = '1'; }

	?>
	<div class="thw-frontend-wrapper">
		<style>
			.thw-frontend-wrapper { max-width: 800px; margin: 0 auto; font-family: sans-serif; }
			.thw-card { background: #f9f9f9; border: 1px solid #ddd; padding: 20px; margin-bottom: 20px; border-radius: 5px; }
			.thw-flex { display: flex; gap: 15px; flex-wrap: wrap; }
			.thw-col { flex: 1; min-width: 200px; }
			.thw-btn { background: #003399; color: #fff; border: none; padding: 8px 15px; cursor: pointer; border-radius: 3px; text-decoration: none; display: inline-block; }
			.thw-btn:hover { background: #002266; color: #fff; }
			.thw-msg { padding: 10px; margin-bottom: 15px; border-radius: 3px; }
			.thw-msg.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
			table.thw-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
			table.thw-table th, table.thw-table td { text-align: left; padding: 8px; border-bottom: 1px solid #ddd; }
			label { font-weight: bold; display: block; margin-bottom: 5px; }
			select, input[type="date"] { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 3px; }
			.thw-table-wrapper { overflow-x: auto; -webkit-overflow-scrolling: touch; margin-bottom: 15px; }
			
			/* Mobile Optimierung */
			@media (max-width: 600px) {
				.thw-col { flex: 1 1 100%; }
				table.thw-table thead { display: none; }
				table.thw-table tr { display: block; margin-bottom: 15px; border: 1px solid #ddd; background: #fff; padding: 10px; border-radius: 5px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
				table.thw-table td { display: block; padding: 5px 0; border: none; border-bottom: 1px solid #eee; }
				table.thw-table td:first-child { font-weight: bold; font-size: 1.1em; border-bottom: 1px solid #eee; margin-bottom: 5px; padding-bottom: 5px; }
				table.thw-table td:last-child { border-bottom: none; }
			}
		</style>

		<?php echo $msg; ?>

		<!-- 1. FILTER & AUSWAHL -->
		<div class="thw-card">
			<form method="get" action="<?php echo esc_url( $page_url ); ?>">
				<input type="hidden" name="view" value="absences">
				<div class="thw-flex">
					<div class="thw-col">
						<label for="filter_zug">Zug filtern:</label>
						<select name="filter_zug" id="filter_zug" onchange="this.form.submit()">
							<option value="">-- Alle Züge --</option>
							<?php foreach ( $zuege as $z ) : ?>
								<option value="<?php echo esc_attr( $z ); ?>" <?php selected( $filter_zug, $z ); ?>>
									<?php echo esc_html( $z ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="thw-col">
						<label for="filter_unit">Einheit filtern:</label>
						<select name="filter_unit" id="filter_unit" onchange="this.form.submit()">
							<option value="0">-- Alle Einheiten --</option>
							<?php foreach ( $units as $u ) : ?>
								<option value="<?php echo $u->id; ?>" <?php selected( $filter_unit_id, $u->id ); ?>>
									<?php echo esc_html( $u->zug . ' - ' . $u->bezeichnung ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="thw-col">
						<label for="selected_user">Person wählen:</label>
						<select name="selected_user" id="selected_user" onchange="this.form.submit()">
							<option value="0">-- Bitte wählen --</option>
							<?php foreach ( $users as $user ) : ?>
								<option value="<?php echo $user->ID; ?>" <?php selected( $selected_user_id, $user->ID ); ?>>
									<?php echo esc_html( $user_names[ $user->ID ] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="thw-col" style="display: flex; align-items: center; padding-top: 15px;">
						<input type="hidden" name="filter_submitted" value="1">
						<label for="hide_past" style="margin-bottom: 0; font-weight: normal; cursor: pointer;">
							<input type="checkbox" name="hide_past" id="hide_past" value="1" <?php checked( $hide_past ); ?> onchange="this.form.submit()">
							Vergangene ausblenden
						</label>
					</div>
				</div>
			</form>
		</div>

		<!-- 2. VERWALTUNG (Nur wenn User gewählt) -->
		<?php if ( $selected_user_id > 0 ) : ?>
			<div class="thw-card">
				<h3>Abwesenheit <?php echo $edit_entry ? 'bearbeiten' : 'eintragen'; ?></h3>
				<form method="post" action="<?php echo esc_url( $base_url ); ?>">
					<?php wp_nonce_field( 'thw_save_absence_nonce' ); ?>
					<input type="hidden" name="thw_save_absence" value="1">
					<input type="hidden" name="selected_user" value="<?php echo $selected_user_id; ?>">
					<input type="hidden" name="filter_unit" value="<?php echo $filter_unit_id; ?>">
					<input type="hidden" name="filter_zug" value="<?php echo esc_attr( $filter_zug ); ?>">
					<input type="hidden" name="abs_id" value="<?php echo $edit_entry ? $edit_entry->id : 0; ?>">

					<div class="thw-flex" style="align-items: flex-end;">
						<div class="thw-col">
							<label>Von:</label>
							<input type="date" name="start_date" required value="<?php echo $edit_entry ? esc_attr( $edit_entry->start_date ) : ''; ?>">
						</div>
						<div class="thw-col">
							<label>Bis:</label>
							<input type="date" name="end_date" required value="<?php echo $edit_entry ? esc_attr( $edit_entry->end_date ) : ''; ?>">
						</div>
						<div class="thw-col" style="flex: 2;">
							<label>Grund (z.B. Urlaub):</label>
							<input type="text" name="reason" required placeholder="Grund der Abwesenheit" value="<?php echo $edit_entry ? esc_attr( $edit_entry->reason ) : ''; ?>">
						</div>
						<div class="thw-col" style="flex: 0 0 auto;">
							<button type="submit" class="thw-btn"><?php echo $edit_entry ? 'Änderung speichern' : 'Hinzufügen'; ?></button>
							<?php if ( $edit_entry ) : ?>
								<a href="<?php echo esc_url( add_query_arg( $link_args, $page_url ) ); ?>" class="thw-btn" style="background:#ccc; color:#333;">Abbrechen</a>
							<?php endif; ?>
						</div>
					</div>
				</form>

				<hr style="margin: 20px 0; border: 0; border-top: 1px solid #eee;">

				<h4>Vorhandene Einträge</h4>
				<?php
				$sql_where = "WHERE user_id = %d";
				if ( $hide_past ) {
					$sql_where .= " AND end_date >= CURDATE()";
				}
				$absences = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table_absences $sql_where ORDER BY start_date DESC", $selected_user_id ) );
				if ( empty( $absences ) ) : echo '<p>Keine Abwesenheiten eingetragen.</p>'; else : ?>
					<div class="thw-table-wrapper">
					<table class="thw-table">
						<thead><tr><th>Von</th><th>Bis</th><th>Grund</th><th>Aktion</th></tr></thead>
						<tbody>
							<?php foreach ( $absences as $abs ) : ?>
								<tr>
									<td><?php echo date_i18n( 'd.m.Y', strtotime( $abs->start_date ) ); ?></td>
									<td><?php echo date_i18n( 'd.m.Y', strtotime( $abs->end_date ) ); ?></td>
									<td><?php echo esc_html( $abs->reason ); ?></td>
									<td>
										<a href="<?php echo esc_url( add_query_arg( array_merge( $link_args, array( 'action' => 'edit', 'abs_id' => $abs->id ) ), $page_url ) ); ?>">Bearbeiten</a> | 
										<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array_merge( $link_args, array( 'action' => 'delete', 'abs_id' => $abs->id ) ), $page_url ), 'delete_absence_' . $abs->id ) ); ?>" style="color:#a00;" onclick="return confirm('Löschen?');">Löschen</a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					</div>
				<?php endif; ?>
			</div>
		<?php else : ?>
			<!-- 3. ÜBERSICHT (Wenn kein User gewählt) -->
			<div class="thw-card">
				<h3><?php echo $filter_unit_id > 0 ? 'Alle Abwesenheiten in dieser Einheit' : ( ! empty( $filter_zug ) ? 'Alle Abwesenheiten in diesem Zug' : 'Alle Abwesenheiten (Gesamtübersicht)' ); ?></h3>
				<?php
				$unit_user_ids = array_keys( $user_names );
				$unit_absences = array();
				
				if ( ! empty( $unit_user_ids ) ) {
					$placeholders = implode( ',', array_fill( 0, count( $unit_user_ids ), '%d' ) );
					// Alle Abwesenheiten anzeigen (Vergangenheit, Aktuell, Zukunft)
					$sql = "SELECT * FROM $table_absences WHERE user_id IN ($placeholders) ORDER BY start_date DESC";
					if ( $hide_past ) {
						$sql = "SELECT * FROM $table_absences WHERE user_id IN ($placeholders) AND end_date >= CURDATE() ORDER BY start_date DESC";
					}
					$unit_absences = $wpdb->get_results( $wpdb->prepare( $sql, $unit_user_ids ) );
				}

				if ( empty( $unit_absences ) ) : 
					echo '<p>Keine Abwesenheiten gefunden.</p>'; 
				else : ?>
					<div class="thw-table-wrapper">
					<table class="thw-table">
						<thead><tr><th>Name</th><th>Von</th><th>Bis</th><th>Grund</th><th>Aktion</th></tr></thead>
						<tbody>
							<?php foreach ( $unit_absences as $abs ) : 
								$u_name = isset( $user_names[ $abs->user_id ] ) ? $user_names[ $abs->user_id ] : 'Unbekannt';
							?>
								<tr>
									<td><strong><?php echo esc_html( $u_name ); ?></strong></td>
									<td><?php echo date_i18n( 'd.m.Y', strtotime( $abs->start_date ) ); ?></td>
									<td><?php echo date_i18n( 'd.m.Y', strtotime( $abs->end_date ) ); ?></td>
									<td><?php echo esc_html( $abs->reason ); ?></td>
									<td>
										<a href="<?php echo esc_url( add_query_arg( array_merge( $link_args, array( 'action' => 'edit', 'abs_id' => $abs->id, 'selected_user' => $abs->user_id ) ), $page_url ) ); ?>">Bearbeiten</a> | 
										<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array_merge( $link_args, array( 'action' => 'delete', 'abs_id' => $abs->id, 'selected_user' => 0 ) ), $page_url ), 'delete_absence_' . $abs->id ) ); ?>" style="color:#a00;" onclick="return confirm('Löschen?');">Löschen</a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					</div>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}