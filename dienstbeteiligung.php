<?php
// Direktzugriff verhindern
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode Funktion für den Tab "Dienstbeteiligung"
 */
function thw_dm_participation_shortcode() {
	global $wpdb;
	$table_services = $wpdb->prefix . 'thw_services';
	$table_units    = $wpdb->prefix . 'thw_units';
	$table_absences = $wpdb->prefix . 'thw_absences';

	$page_url = add_query_arg( 'view', 'participation', get_permalink() );
	$selected_service_id = isset( $_REQUEST['service_id'] ) ? intval( $_REQUEST['service_id'] ) : 0;

	ob_start();

	// Dienst laden (für Status-Check vor dem Speichern)
	$service_status = 'open';
	if ( $selected_service_id > 0 ) {
		$current_service = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_services WHERE id = %d", $selected_service_id ) );
		if ( $current_service ) {
			$service_status = isset( $current_service->status ) ? $current_service->status : 'open';
		}
	}
	
	$is_admin = current_user_can( 'administrator' );
	$can_edit = $is_admin || $service_status !== 'closed';

	// --- SPEICHERN ---
	if ( isset( $_POST['thw_save_participation'] ) && check_admin_referer( 'thw_save_participation_nonce' ) && $can_edit ) {
		// Sicherheitscheck: Nur speichern wenn erlaubt
		
		$service_id = intval( $_POST['service_id'] );
		
		$attendance = array();

		// 1. WP-Benutzer (über ID)
		$attendance_raw = isset( $_POST['attendance'] ) ? $_POST['attendance'] : array();
		foreach ( $attendance_raw as $uid => $status ) {
			if ( ! empty( $status ) ) {
				$user_obj = get_userdata( intval( $uid ) );
				if ( $user_obj ) {
					$lastname = $user_obj->last_name;
					$firstname = $user_obj->first_name;
					$fullname = $user_obj->display_name;
					if ( ! empty( $lastname ) ) $fullname = $lastname . ( ! empty( $firstname ) ? ', ' . $firstname : '' );
					
					$attendance[ $fullname ] = sanitize_key( $status );
				}
			}
		}

		// 2. Manuelle Einträge (über Name)
		if ( isset( $_POST['attendance_manual'] ) && is_array( $_POST['attendance_manual'] ) ) {
			foreach ( $_POST['attendance_manual'] as $name => $status ) {
				if ( $status !== 'delete' ) {
					$attendance[ sanitize_text_field( $name ) ] = sanitize_key( $status );
				}
			}
		}

		// 4. Person aus System hinzufügen (Dropdown)
		if ( ! empty( $_POST['add_system_user'] ) ) {
			$sys_name = sanitize_text_field( $_POST['add_system_user'] );
			if ( ! empty( $sys_name ) ) {
				$attendance[ $sys_name ] = 'present';
			}
		}

		// 3. Neue Person hinzufügen
		if ( ! empty( $_POST['new_person_name'] ) ) {
			$new_name = sanitize_text_field( $_POST['new_person_name'] );
			if ( ! empty( $new_name ) ) {
				$attendance[ $new_name ] = 'present'; // Standard: Anwesend
			}
		}
		
		$wpdb->update( 
			$table_services, 
			array( 'attendance' => maybe_serialize( $attendance ) ), 
			array( 'id' => $service_id ) 
		);

		// AJAX Antwort senden, falls Anfrage über JavaScript kam
		if ( ! empty( $_SERVER['HTTP_X_REQUESTED_WITH'] ) && strtolower( $_SERVER['HTTP_X_REQUESTED_WITH'] ) == 'xmlhttprequest' ) {
			while ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
			wp_send_json_success( 'Gespeichert' );
		}

		echo '<div class="thw-msg success" style="background: #d4edda; color: #155724; padding: 10px; margin-bottom: 15px; border: 1px solid #c3e6cb; border-radius: 3px;">Anwesenheit gespeichert.</div>';
		$selected_service_id = $service_id; // Auswahl beibehalten
	}
	
	// --- STATUS ÄNDERN (Admin) ---
	if ( isset( $_POST['thw_dm_status_action'] ) && $is_admin && check_admin_referer( 'thw_dm_status_nonce' ) ) {
		$new_status = $_POST['thw_dm_status_action'] === 'close' ? 'closed' : 'open';
		$wpdb->update( $table_services, array( 'status' => $new_status ), array( 'id' => $selected_service_id ) );
		$service_status = $new_status;
		$can_edit = $is_admin || $service_status !== 'closed'; // Status update
	}

	// Dienste laden
	$services = $wpdb->get_results( "SELECT * FROM $table_services ORDER BY service_date ASC" );
	
	// Alle Einheiten laden (für Map)
	$all_units = $wpdb->get_results( "SELECT * FROM $table_units" );
	$unit_name_map = array();
	foreach ( $all_units as $u ) { $unit_name_map[ $u->id ] = $u->zug . ' - ' . $u->bezeichnung; }

	// Alle User laden (für Dropdown und Map)
	$all_users = get_users();
	usort( $all_users, function( $a, $b ) {
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

	$user_unit_map = array();
	foreach ( $all_users as $u ) { 
		$lastname = $u->last_name;
		$firstname = $u->first_name;
		$fullname = ! empty( $lastname ) ? $lastname . ( ! empty( $firstname ) ? ', ' . $firstname : '' ) : $u->display_name;
		$user_unit_map[ $fullname ] = get_user_meta( $u->ID, 'thw_unit_id', true ); 
	}

	?>
	<div class="thw-frontend-wrapper">
		<style>
			.thw-frontend-wrapper { max-width: 1000px; margin: 0 auto; font-family: sans-serif; }
			.thw-card { background: #f9f9f9; border: 1px solid #ddd; padding: 20px; margin-bottom: 20px; border-radius: 5px; }
			.thw-btn { background: #003399; color: #fff; border: none; padding: 8px 15px; cursor: pointer; border-radius: 3px; }
			.thw-btn:hover { background: #002266; }
			table.thw-table { width: 100%; border-collapse: collapse; margin-top: 10px; background: #fff; }
			table.thw-table th, table.thw-table td { text-align: left; padding: 10px; border-bottom: 1px solid #eee; }
			table.thw-table th { background: #eee; }
			select { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 3px; }
			.thw-table-wrapper { overflow-x: auto; -webkit-overflow-scrolling: touch; margin-bottom: 15px; }
			
			/* Status Buttons */
			.thw-status-buttons { display: flex; gap: 5px; flex-wrap: wrap; }
			.status-btn { display: inline-block; width: 34px; height: 34px; cursor: pointer; margin: 0; position: relative; }
			.status-btn input { position: absolute; opacity: 0; cursor: pointer; height: 0; width: 0; }
			.status-btn span { display: flex; align-items: center; justify-content: center; width: 100%; height: 100%; border: 1px solid #ccc; border-radius: 4px; background: #f8f9fa; font-weight: bold; color: #555; font-size: 1.1em; transition: all 0.2s; }
			.status-btn:hover span { background: #e2e6ea; }
			
			.status-btn.btn-present input:checked + span { background-color: #28a745; color: #fff; border-color: #28a745; } /* X - Grün */
			.status-btn.btn-leave input:checked + span { background-color: #ffc107; color: #212529; border-color: #ffc107; } /* E - Gelb */
			.status-btn.btn-sick input:checked + span { background-color: #ffc107; color: #212529; border-color: #ffc107; } /* K - Gelb */
			.status-btn.btn-unexcused input:checked + span { background-color: #dc3545; color: #fff; border-color: #dc3545; } /* U - Rot */
			.status-btn.btn-delete input:checked + span { background-color: #343a40; color: #fff; border-color: #343a40; } /* Löschen */
			.status-btn input:disabled + span { opacity: 0.5; cursor: not-allowed; }

			/* Mobile Optimierung: Kartenansicht statt Tabelle */
			@media (max-width: 600px) {
				table.thw-table thead { display: none; }
				table.thw-table tr { display: block; margin-bottom: 15px; border: 1px solid #ddd; background: #fff; padding: 10px; border-radius: 5px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
				table.thw-table td { display: block; padding: 5px 0; border: none; }
				table.thw-table td:first-child { font-weight: bold; font-size: 1.1em; border-bottom: 1px solid #eee; margin-bottom: 5px; padding-bottom: 5px; }
			}
		</style>

		<!-- DIENST AUSWAHL -->
		<div class="thw-card">
			<form method="get" action="<?php echo esc_url( $page_url ); ?>">
				<input type="hidden" name="view" value="participation">
				<label style="font-weight:bold; display:block; margin-bottom:5px;">Dienst auswählen:</label>
				<select name="service_id" onchange="this.form.submit()">
					<option value="">-- Bitte wählen --</option>
					<?php foreach ( $services as $s ) : ?>
						<option value="<?php echo $s->id; ?>" <?php selected( $selected_service_id, $s->id ); ?>>
							<?php echo date_i18n( 'd.m.Y', strtotime( $s->service_date ) ) . ' - ' . esc_html( $s->name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</form>
		</div>

		<?php if ( $selected_service_id > 0 ) : 
			$service = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_services WHERE id = %d", $selected_service_id ) );
			$unit_ids = maybe_unserialize( $service->unit_ids );
			
			// Admin Status Box
			if ( $is_admin ) : ?>
				<div class="thw-card" style="border-left: 5px solid <?php echo $service_status === 'closed' ? '#d63638' : '#46b450'; ?>;">
					<form method="post">
						<?php wp_nonce_field( 'thw_dm_status_nonce' ); ?>
						<input type="hidden" name="service_id" value="<?php echo $selected_service_id; ?>">
						<?php if ( $service_status === 'closed' ) : ?>
							<strong>Status: Abgeschlossen.</strong> Führungskräfte können nicht mehr bearbeiten. 
							<button type="submit" name="thw_dm_status_action" value="open" class="thw-btn" style="background:#444; font-size:0.9em; margin-left:10px;">Wieder öffnen</button>
						<?php else : ?>
							<strong>Status: Offen.</strong> 
							<button type="submit" name="thw_dm_status_action" value="close" class="thw-btn" style="background:#d63638; font-size:0.9em; margin-left:10px;">Dienst abschließen</button>
						<?php endif; ?>
					</form>
				</div>
			<?php elseif ( ! $can_edit ) : ?>
				<div class="thw-message error" style="background:#f8d7da; color:#721c24; padding:10px; margin-bottom:20px; border:1px solid #f5c6cb;">Dieser Dienst wurde vom Administrator abgeschlossen. Bearbeitung nicht mehr möglich.</div>
			<?php endif;
			
			$saved_attendance = maybe_unserialize( $service->attendance );
			if ( ! is_array( $saved_attendance ) ) $saved_attendance = array();

			$displayed_names = array(); // Um doppelte Anzeige zu vermeiden

			// Migration: Altes Format (Liste von IDs) in neues Format (Assoc Array) umwandeln
			if ( ! empty( $saved_attendance ) ) {
				$first_val = reset( $saved_attendance );
				$first_key = key( $saved_attendance );

				// Fall 1: Alte Liste nur mit IDs [1, 2, 3]
				if ( is_numeric( $first_val ) && is_int( $first_key ) ) {
					$converted = array();
					foreach ( $saved_attendance as $uid ) {
						$u = get_userdata( $uid );
						if ( $u ) {
							$lastname = $u->last_name;
							$firstname = $u->first_name;
							$fullname = $u->display_name;
							if ( ! empty( $lastname ) ) $fullname = $lastname . ( ! empty( $firstname ) ? ', ' . $firstname : '' );
							
							$converted[ $fullname ] = 'present';
						}
					}
					$saved_attendance = $converted;
				}
				// Fall 2: Liste mit ID => Status [1 => 'present']
				elseif ( is_int( $first_key ) ) {
					$converted = array();
					foreach ( $saved_attendance as $uid => $status ) {
						$u = get_userdata( $uid );
						if ( $u ) {
							$lastname = $u->last_name;
							$firstname = $u->first_name;
							$fullname = $u->display_name;
							if ( ! empty( $lastname ) ) $fullname = $lastname . ( ! empty( $firstname ) ? ', ' . $firstname : '' );
							
							$converted[ $fullname ] = $status;
						}
					}
					$saved_attendance = $converted;
				}
			}

			// Summe der Anwesenden berechnen
			$total_present = 0;
			foreach ( $saved_attendance as $status ) {
				if ( $status === 'present' ) {
					$total_present++;
				}
			}
			?>
			<div class="thw-card" style="text-align:center; font-size:1.2em; padding:15px; border-left:5px solid #003399;">
				<strong>Gesamtstärke (Anwesend): <span id="total-present-count"><?php echo intval( $total_present ); ?></span></strong>
			</div>

			<?php
			if ( ! empty( $unit_ids ) && is_array( $unit_ids ) ) :
				?>
				<form method="post" action="<?php echo esc_url( $page_url ); ?>" id="thw-participation-form">
					<?php wp_nonce_field( 'thw_save_participation_nonce' ); ?>
					<input type="hidden" name="thw_save_participation" value="1">
					<input type="hidden" name="service_id" value="<?php echo $selected_service_id; ?>">

					<?php foreach ( $unit_ids as $uid ) : 
						$unit = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_units WHERE id = %d", $uid ) );
						if ( ! $unit ) continue;

						// Benutzer dieser Einheit holen
						$users = get_users( array( 
							'meta_key' => 'thw_unit_id', 
							'meta_value' => $uid, 
						) );
						
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
						
						<div class="thw-card" style="border-left: 5px solid #003399;">
							<h3 style="margin-top:0;"><?php echo esc_html( $unit->zug . ' - ' . $unit->bezeichnung ); ?></h3>
							
							<?php if ( empty( $users ) ) : ?>
								<p>Keine Helfer in dieser Einheit.</p>
							<?php else : ?>
								<div class="thw-table-wrapper">
								<table class="thw-table">
									<thead><tr><th>Name</th><th>Status (Abwesenheit)</th><th style="text-align:left;">Beteiligung</th></tr></thead>
									<tbody>
										<?php foreach ( $users as $user ) : 
											// Prüfen ob Abwesend an diesem Tag
											$absence = $wpdb->get_row( $wpdb->prepare(
												"SELECT * FROM $table_absences WHERE user_id = %d AND %s BETWEEN start_date AND end_date",
												$user->ID, $service->service_date
											) );
											
											$is_absent = ! empty( $absence );
											$status_html = $is_absent 
												? '<span style="color:#a00; font-weight:bold;">Abwesend: ' . esc_html( $absence->reason ) . '</span>' 
												: '<span style="color:#999;">-</span>';
											
											$lastname = $user->last_name;
											$firstname = $user->first_name;
											$fullname = ! empty( $lastname ) ? $lastname . ( ! empty( $firstname ) ? ', ' . $firstname : '' ) : $user->display_name;
											
											$current_status = isset( $saved_attendance[ $fullname ] ) ? $saved_attendance[ $fullname ] : '';
											$displayed_names[] = $fullname;
										?>
										<tr style="<?php echo $is_absent ? 'background-color:#fff5f5;' : ''; ?>">
											<td><?php echo esc_html( $fullname ); ?></td>
											<td><?php echo $status_html; ?></td>
											<td style="text-align:left;">
												<div class="thw-status-buttons">
													<label class="status-btn btn-present" title="Anwesend"><input type="radio" name="attendance[<?php echo $user->ID; ?>]" value="present" <?php checked( $current_status, 'present' ); ?> <?php disabled( ! $can_edit ); ?>><span>X</span></label>
													<label class="status-btn btn-leave" title="Entschuldigt / Beurlaubt"><input type="radio" name="attendance[<?php echo $user->ID; ?>]" value="leave" <?php checked( $current_status, 'leave' ); ?> <?php disabled( ! $can_edit ); ?>><span>E</span></label>
													<label class="status-btn btn-sick" title="Krank"><input type="radio" name="attendance[<?php echo $user->ID; ?>]" value="sick" <?php checked( $current_status, 'sick' ); ?> <?php disabled( ! $can_edit ); ?>><span>K</span></label>
													<label class="status-btn btn-unexcused" title="Unentschuldigt"><input type="radio" name="attendance[<?php echo $user->ID; ?>]" value="unexcused" <?php checked( $current_status, 'unexcused' ); ?> <?php disabled( ! $can_edit ); ?>><span>U</span></label>
												</div>
											</td>
										</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
								</div>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>

					<!-- ZUSÄTZLICHE TEILNEHMER (Manuell hinzugefügt oder ehemalige User) -->
					<?php
					$manual_entries = array();
					foreach ( $saved_attendance as $name => $status ) {
						// Nur anzeigen, wenn nicht schon oben gelistet
						if ( ! in_array( $name, $displayed_names ) ) {
							$manual_entries[ $name ] = $status;
						}
					}
					?>
					
					<div class="thw-card" style="border-left: 5px solid #777;">
						<h3 style="margin-top:0;">Weitere Teilnehmer (z.B. Ehemalige, Gäste)</h3>
						<p style="font-size:0.9em; color:#666; margin-bottom:10px;">
							Hier erscheinen Personen, die manuell hinzugefügt wurden oder die Einheit gewechselt haben.
						</p>
						<div class="thw-table-wrapper">
						<table class="thw-table">
							<thead><tr><th>Name</th><th style="text-align:left;">Beteiligung</th></tr></thead>
							<tbody>
								<?php if ( ! empty( $manual_entries ) ) : ?>
									<?php foreach ( $manual_entries as $name => $status ) : ?>
										<?php 
											// Prüfen, ob User im System existiert und aktuelle Einheit anzeigen
											$extra_info = '';
											if ( isset( $user_unit_map[ $name ] ) ) {
												$u_id = $user_unit_map[ $name ];
												if ( isset( $unit_name_map[ $u_id ] ) ) {
													$extra_info = '<br><span style="font-size:0.85em; color:#003399;">(Aktuell: ' . esc_html( $unit_name_map[ $u_id ] ) . ')</span>';
												} else {
													$extra_info = '<br><span style="font-size:0.85em; color:#666;">(Keine Einheit zugeordnet)</span>';
												}
											}
										?>
										<tr>
											<td>
												<?php echo esc_html( $name ); ?>
												<?php echo $extra_info; ?>
											</td>
											<td style="text-align:left;">
												<div class="thw-status-buttons">
													<label class="status-btn btn-present" title="Anwesend"><input type="radio" name="attendance_manual[<?php echo esc_attr( $name ); ?>]" value="present" <?php checked( $status, 'present' ); ?> <?php disabled( ! $can_edit ); ?>><span>X</span></label>
													<label class="status-btn btn-leave" title="Entschuldigt / Beurlaubt"><input type="radio" name="attendance_manual[<?php echo esc_attr( $name ); ?>]" value="leave" <?php checked( $status, 'leave' ); ?> <?php disabled( ! $can_edit ); ?>><span>E</span></label>
													<label class="status-btn btn-sick" title="Krank"><input type="radio" name="attendance_manual[<?php echo esc_attr( $name ); ?>]" value="sick" <?php checked( $status, 'sick' ); ?> <?php disabled( ! $can_edit ); ?>><span>K</span></label>
													<label class="status-btn btn-unexcused" title="Unentschuldigt"><input type="radio" name="attendance_manual[<?php echo esc_attr( $name ); ?>]" value="unexcused" <?php checked( $status, 'unexcused' ); ?> <?php disabled( ! $can_edit ); ?>><span>U</span></label>
													<?php if ( $can_edit ) : ?>
														<label class="status-btn btn-delete" title="Löschen"><input type="radio" name="attendance_manual[<?php echo esc_attr( $name ); ?>]" value="delete"><span>🗑</span></label>
													<?php endif; ?>
												</div>
											</td>
										</tr>
									<?php endforeach; ?>
								<?php else : ?>
									<tr><td colspan="2" style="color:#777;">Keine zusätzlichen Teilnehmer eingetragen.</td></tr>
								<?php endif; ?>
								
								<?php if ( $can_edit ) : ?>
									<!-- Person aus System hinzufügen -->
									<tr style="background-color:#f0f0f0; border-top:2px solid #ddd;">
										<td>
											<select name="add_system_user" style="width:100%;">
												<option value="">-- Person aus System hinzufügen --</option>
												<?php foreach ( $all_users as $u ) : 
													$u_unit_name = isset( $unit_name_map[ get_user_meta( $u->ID, 'thw_unit_id', true ) ] ) ? $unit_name_map[ get_user_meta( $u->ID, 'thw_unit_id', true ) ] : 'Ohne Einheit';
												?>
													<?php
														$lastname = $u->last_name;
														$firstname = $u->first_name;
														$fullname = ! empty( $lastname ) ? $lastname . ( ! empty( $firstname ) ? ', ' . $firstname : '' ) : $u->display_name;
													?>
													<option value="<?php echo esc_attr( $fullname ); ?>">
														<?php echo esc_html( $fullname ); ?> (<?php echo esc_html( $u_unit_name ); ?>)
													</option>
												<?php endforeach; ?>
											</select>
										</td>
										<td style="vertical-align:middle; color:#555;">&larr; Auswählen und "Speichern" (für Nutzer, die die Einheit gewechselt haben).</td>
									</tr>
									<!-- Manueller Name -->
									<tr style="background-color:#f0f0f0;">
										<td><input type="text" name="new_person_name" placeholder="Oder: Name manuell eingeben (Gast)..." style="width:100%;"></td>
										<td style="vertical-align:middle; color:#555;">&larr; Name eingeben und "Speichern" klicken zum Hinzufügen.</td>
									</tr>
								<?php endif; ?>
							</tbody>
						</table>
						</div>
					</div>

					<?php if ( $can_edit ) : ?>
						<div style="text-align:right;"><button type="submit" class="thw-btn" style="font-size:1.1em;">Anwesenheit speichern</button></div>
					<?php endif; ?>
				</form>

				<!-- AJAX Speicher-Logik -->
				<div id="thw-toast" style="display:none; position:fixed; bottom:20px; right:20px; background:#333; color:#fff; padding:10px 20px; border-radius:5px; z-index:9999; box-shadow: 0 2px 5px rgba(0,0,0,0.3);">Gespeichert</div>

				<script>
				document.addEventListener('DOMContentLoaded', function() {
					var form = document.getElementById('thw-participation-form');
					var toast = document.getElementById('thw-toast');

					if (form) {
						// Logik für das Abwählen von Radio-Buttons (Toggle)
						var allRadios = form.querySelectorAll('input[type="radio"]');
						allRadios.forEach(function(r) {
							r.setAttribute('data-was-checked', r.checked ? 'true' : 'false');
						});

						form.addEventListener('click', function(e) {
							if (e.target.type === 'radio' && e.target.name.indexOf('attendance') === 0) {
								if (e.target.getAttribute('data-was-checked') === 'true') {
									// Bereits ausgewählt -> Abwählen
									e.target.checked = false;
									e.target.setAttribute('data-was-checked', 'false');
									// Change-Event manuell feuern, damit gespeichert wird
									e.target.dispatchEvent(new Event('change', { bubbles: true }));
								} else {
									// Neu ausgewählt -> Status aktualisieren
									var groupName = e.target.name;
									var groupRadios = form.querySelectorAll('input[name="' + groupName.replace(/"/g, '\\"') + '"]');
									groupRadios.forEach(function(r) {
										r.setAttribute('data-was-checked', 'false');
									});
									e.target.setAttribute('data-was-checked', 'true');
								}
							}
						});

						form.addEventListener('change', function(e) {
							// Nur reagieren, wenn es ein Radio-Button für Anwesenheit ist
							if (e.target.type === 'radio' && (e.target.name.startsWith('attendance') || e.target.name.startsWith('attendance_manual'))) {
								
								// Feedback anzeigen
								toast.innerText = 'Speichere...';
								toast.style.display = 'block';
								toast.style.backgroundColor = '#555';

								// Zähler aktualisieren
								var presentCount = form.querySelectorAll('input[type="radio"][value="present"]:checked').length;
								var countDisplay = document.getElementById('total-present-count');
								if (countDisplay) {
									countDisplay.innerText = presentCount;
								}

								var formData = new FormData(form);
								
								fetch(form.action, {
									method: 'POST',
									body: formData,
									headers: { 'X-Requested-With': 'XMLHttpRequest' }
								})
								.then(response => {
									if (!response.ok) { throw new Error('Netzwerk-Antwort war nicht ok'); }
									return response.json();
								})
								.then(data => {
									if (data.success) {
										toast.innerText = 'Gespeichert';
										toast.style.backgroundColor = '#28a745';
										
										// Bei "Löschen" Seite neu laden, damit Zeile verschwindet
										if (e.target.value === 'delete') {
											window.location.reload();
										} else {
											setTimeout(function() { toast.style.display = 'none'; }, 2000);
										}
									} else {
										toast.innerText = 'Fehler';
										toast.style.backgroundColor = '#dc3545';
										setTimeout(function() { toast.style.display = 'none'; }, 3000);
									}
								})
								.catch(error => {
									console.error('Fehler:', error);
									toast.innerText = 'Fehler beim Speichern';
									toast.style.backgroundColor = '#dc3545';
									setTimeout(function() { toast.style.display = 'none'; }, 3000);
								});
							}
						});
					}
				});
				</script>
			<?php endif; ?>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}