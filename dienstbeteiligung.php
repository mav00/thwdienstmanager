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
	$filter_zug = isset( $_REQUEST['filter_zug'] ) ? sanitize_text_field( $_REQUEST['filter_zug'] ) : '';

	ob_start();

	$is_admin = current_user_can( 'administrator' );

	// --- STATUS ÄNDERN (Admin) - Vorab ausführen ---
	if ( isset( $_POST['thw_dm_status_action'] ) && $is_admin && check_admin_referer( 'thw_dm_status_nonce' ) ) {
		$new_status = $_POST['thw_dm_status_action'] === 'close' ? 'closed' : 'open';
		if ( $selected_service_id > 0 ) {
			$result = $wpdb->update( $table_services, array( 'status' => $new_status ), array( 'id' => $selected_service_id ) );
			if ( false === $result ) {
				echo '<div class="thw-message error">Fehler beim Status-Update: ' . esc_html( $wpdb->last_error ) . '</div>';
			} else {
				echo '<div class="thw-message success">Status geändert: ' . ($new_status == 'closed' ? 'Abgeschlossen' : 'Geöffnet') . '</div>';
			}
		}
	}

	// Dienst laden (für Status-Check vor dem Speichern)
	$service_status = 'open';
	if ( $selected_service_id > 0 ) {
		$current_service = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_services WHERE id = %d", $selected_service_id ) );
		if ( $current_service ) {
			$service_status = isset( $current_service->status ) ? $current_service->status : 'open';
		}
	}
	
	$can_edit = $service_status !== 'closed';

	// --- SPEICHERN ---
	if ( isset( $_POST['thw_save_participation'] ) && check_admin_referer( 'thw_save_participation_nonce' ) ) {
		
		if ( ! $can_edit ) {
			// Wenn geschlossen, aber AJAX Request -> Fehler senden
			if ( ! empty( $_SERVER['HTTP_X_REQUESTED_WITH'] ) && strtolower( $_SERVER['HTTP_X_REQUESTED_WITH'] ) == 'xmlhttprequest' ) {
				ob_clean();
				wp_send_json_error( 'Dienst ist abgeschlossen. Änderungen wurden nicht gespeichert.' );
			}
		} else {
			// Sicherheitscheck: Nur speichern wenn erlaubt
		
		$service_id = intval( $_POST['service_id'] );
		$posted_filter_zug = isset( $_POST['filter_zug'] ) ? sanitize_text_field( $_POST['filter_zug'] ) : '';
		
		// Bestehende Anwesenheit laden (für Merge bei Filterung)
		$current_service_data = $wpdb->get_row( $wpdb->prepare( "SELECT attendance FROM $table_services WHERE id = %d", $service_id ) );
		$attendance = array();
		if ( $current_service_data ) {
			$attendance = maybe_unserialize( $current_service_data->attendance );
			if ( ! is_array( $attendance ) ) $attendance = array();
		}

		// Wenn gefiltert wurde, müssen wir die User des Filters aus dem bestehenden Array entfernen,
		// bevor wir die neuen Daten (die den aktuellen Stand des Filters repräsentieren) hinzufügen.
		if ( ! empty( $posted_filter_zug ) ) {
			// Einheiten des Zugs holen
			$zug_unit_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $table_units WHERE zug = %s", $posted_filter_zug ) );
			
			if ( ! empty( $zug_unit_ids ) ) {
				// User dieser Einheiten holen
				$users_in_zug = get_users( array( 'meta_key' => 'thw_unit_id', 'meta_value' => $zug_unit_ids, 'meta_compare' => 'IN' ) );
				
				foreach ( $users_in_zug as $u ) {
					$lastname = $u->last_name;
					$firstname = $u->first_name;
					$fullname = $u->display_name;
					if ( ! empty( $lastname ) ) $fullname = $lastname . ( ! empty( $firstname ) ? ', ' . $firstname : '' );
					
					// Eintrag entfernen (wird gleich aus POST neu gesetzt, falls ausgewählt)
					if ( isset( $attendance[ $fullname ] ) ) {
						unset( $attendance[ $fullname ] );
					}
				}
			}
		} else {
			// Kein Filter: Wir überschreiben alles (Clean Slate), außer wir wollen manuelle Einträge behalten?
			// Standardverhalten war bisher Clean Slate.
			$attendance = array();
		}

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
				$clean_name = sanitize_text_field( $name );
				if ( $status === 'delete' ) {
					if ( isset( $attendance[ $clean_name ] ) ) {
						unset( $attendance[ $clean_name ] );
					}
				} else {
					$attendance[ $clean_name ] = sanitize_key( $status );
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
	}

	// Dienste laden
	$services = $wpdb->get_results( "SELECT * FROM $table_services ORDER BY service_date ASC" );
	
	// Züge laden
	$zuege = $wpdb->get_col( "SELECT DISTINCT zug FROM $table_units ORDER BY zug ASC" );

	// Dienste filtern, falls Zug gewählt
	if ( ! empty( $filter_zug ) ) {
		$zug_unit_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $table_units WHERE zug = %s", $filter_zug ) );
		$filtered_services = array();
		foreach ( $services as $s ) {
			$s_units = maybe_unserialize( $s->unit_ids );
			if ( is_array( $s_units ) && array_intersect( $s_units, $zug_unit_ids ) ) {
				$filtered_services[] = $s;
			}
		}
		$services = $filtered_services;
	}

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
	$vegetarian_map = array(); // Map Name -> IsVegetarian
	foreach ( $all_users as $u ) { 
		$lastname = $u->last_name;
		$firstname = $u->first_name;
		$fullname = ! empty( $lastname ) ? $lastname . ( ! empty( $firstname ) ? ', ' . $firstname : '' ) : $u->display_name;
		$user_unit_map[ $fullname ] = get_user_meta( $u->ID, 'thw_unit_id', true ); 
		
		if ( get_user_meta( $u->ID, 'thw_vegetarian', true ) === '1' ) {
			$vegetarian_map[ $fullname ] = true;
		}
	}

	?>
	<div class="thw-frontend-wrapper">
		<style>
			.thw-card label.thw-radio-wrapper { display: inline-block; margin-right: 2px; cursor: pointer; position: relative; margin-bottom: 0; }
			.thw-radio-wrapper input { position: absolute; opacity: 0; cursor: pointer; height: 0; width: 0; }
			.thw-icon {
				display: inline-block; width: 28px; height: 28px; line-height: 26px;
				text-align: center; border: 1px solid #ccc; border-radius: 4px;
				background: #fff; color: #555; font-weight: bold; font-size: 13px;
				box-sizing: border-box; transition: all 0.2s;
			}
			.thw-radio-wrapper input:checked + .thw-icon.status-present { background-color: #28a745; color: #fff; border-color: #1e7e34; }
			.thw-radio-wrapper input:checked + .thw-icon.status-leave { background-color: #ffc107; color: #212529; border-color: #d39e00; }
			.thw-radio-wrapper input:checked + .thw-icon.status-sick { background-color: #ffc107; color: #212529; border-color: #d39e00; }
			.thw-radio-wrapper input:checked + .thw-icon.status-unexcused { background-color: #dc3545; color: #fff; border-color: #bd2130; }
			.thw-radio-wrapper:hover .thw-icon { border-color: #999; box-shadow: 0 1px 2px rgba(0,0,0,0.1); }

			/* Mobile Optimierung: Tabelle mehrzeilig anzeigen */
			@media (max-width: 600px) {
				.thw-table thead { display: none; }
				.thw-table tr { display: block; margin-bottom: 15px; border: 1px solid #ddd; background: #fff; border-radius: 5px; }
				.thw-table td { display: block; padding: 10px; border: none; }
				
				/* Name und Status in einer Zeile */
				.thw-table td:nth-child(1) { display: inline-block; width: auto; font-weight: bold; padding-right: 5px; }
				.thw-table td:nth-child(2):not(:last-child) { display: inline-block; width: auto; padding-left: 0; color: #666; }
				
				/* Radio Buttons / Aktionen immer in neuer Zeile mit Trennlinie */
				.thw-table td:last-child { display: block; width: 100%; border-top: 1px solid #eee; padding-top: 10px; }
				
				.thw-table select, .thw-table input[type="text"] { width: 100%; box-sizing: border-box; }
			}
		</style>

		<!-- DIENST AUSWAHL -->
		<div class="thw-card">
			<form method="get" action="<?php echo esc_url( $page_url ); ?>">
				<input type="hidden" name="view" value="participation">
				<div class="thw-flex">
					<div class="thw-col">
						<label for="filter_zug">Zug filtern:</label>
						<select name="filter_zug" id="filter_zug" onchange="this.form.submit()">
							<option value="">-- Alle Züge --</option>
							<?php foreach ( $zuege as $z ) : ?>
								<option value="<?php echo esc_attr( $z ); ?>" <?php selected( $filter_zug, $z ); ?>><?php echo esc_html( $z ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="thw-col">
						<label for="service_select">Dienst auswählen:</label>
						<select name="service_id" id="service_select" onchange="this.form.submit()">
							<option value="">-- Bitte wählen --</option>
							<?php foreach ( $services as $s ) : ?>
								<option value="<?php echo $s->id; ?>" <?php selected( $selected_service_id, $s->id ); ?>>
									<?php echo date_i18n( 'd.m.Y', strtotime( $s->service_date ) ) . ' - ' . esc_html( $s->name ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>
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
						<input type="hidden" name="filter_zug" value="<?php echo esc_attr( $filter_zug ); ?>">
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
			$total_vegetarian = 0;
			foreach ( $saved_attendance as $name => $status ) {
				if ( $status === 'present' ) {
					$total_present++;
					if ( isset( $vegetarian_map[ $name ] ) ) {
						$total_vegetarian++;
					}
				}
			}

			// Maximale Stärke berechnen (Alle Helfer der beteiligten Einheiten minus Abwesende)
			$max_strength = 0;
			if ( ! empty( $unit_ids ) && is_array( $unit_ids ) ) {
				$service_users_ids = get_users( array( 
					'meta_key' => 'thw_unit_id', 
					'meta_value' => $unit_ids, 
					'meta_compare' => 'IN',
					'fields' => 'ID'
				) );
				
				if ( ! empty( $service_users_ids ) ) {
					$absent_ids = $wpdb->get_col( $wpdb->prepare(
						"SELECT user_id FROM $table_absences WHERE %s BETWEEN start_date AND end_date",
						$service->service_date
					) );
					$max_strength = count( array_diff( $service_users_ids, $absent_ids ) );
				}
			}
			?>
			<div class="thw-card" style="text-align:center; font-size:1.2em; padding:15px; border-left:5px solid #003399;">
				<strong>Gesamtstärke (Anwesend): <span id="total-present-count"><?php echo intval( $total_present ); ?></span> / <?php echo intval( $max_strength ); ?>
				<br><span style="font-size:0.8em; font-weight:normal; color:#28a745;">Davon Vegetarier: <?php echo intval( $total_vegetarian ); ?> &#127811;</span></strong>
			</div>

			<?php
			if ( ! empty( $unit_ids ) && is_array( $unit_ids ) ) :
				?>
				<form method="post" action="<?php echo esc_url( $page_url ); ?>" id="thw-participation-form">
					<?php wp_nonce_field( 'thw_save_participation_nonce' ); ?>
					<input type="hidden" name="thw_save_participation" value="1">
					<input type="hidden" name="service_id" value="<?php echo $selected_service_id; ?>">
					<input type="hidden" name="filter_zug" value="<?php echo esc_attr( $filter_zug ); ?>">

					<?php foreach ( $unit_ids as $uid ) : 
						$unit = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_units WHERE id = %d", $uid ) );
						if ( ! $unit ) continue;

						if ( ! empty( $filter_zug ) && $unit->zug !== $filter_zug ) continue;

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
									<thead><tr><th>Name</th><th>Abwesenheit&nbsp;</th><th style="text-align:left;">Beteiligung</th></tr></thead>
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

											$is_veg = get_user_meta( $user->ID, 'thw_vegetarian', true ) === '1';
											$veg_icon = $is_veg ? ' <span title="Vegetarier" style="color:#28a745; cursor:help;">&#127811;</span>' : '';
										?>
										<tr style="<?php echo $is_absent ? 'background-color:#fff5f5;' : ''; ?>">
											<td><?php echo esc_html( $fullname ) . $veg_icon; ?></td>
											<td><?php echo $status_html; ?></td>
											<td style="text-align:left; white-space:nowrap;">
												<label class="thw-radio-wrapper" title="Anwesend"><input type="radio" name="attendance[<?php echo $user->ID; ?>]" value="present" <?php checked( $current_status, 'present' ); ?> <?php disabled( ! $can_edit ); ?>><span class="thw-icon status-present">&#10004;</span></label>
												<label class="thw-radio-wrapper" title="Entschuldigt"><input type="radio" name="attendance[<?php echo $user->ID; ?>]" value="leave" <?php checked( $current_status, 'leave' ); ?> <?php disabled( ! $can_edit ); ?>><span class="thw-icon status-leave">E</span></label>
												<label class="thw-radio-wrapper" title="Krank"><input type="radio" name="attendance[<?php echo $user->ID; ?>]" value="sick" <?php checked( $current_status, 'sick' ); ?> <?php disabled( ! $can_edit ); ?>><span class="thw-icon status-sick">K</span></label>
												<label class="thw-radio-wrapper" title="Unentschuldigt"><input type="radio" name="attendance[<?php echo $user->ID; ?>]" value="unexcused" <?php checked( $current_status, 'unexcused' ); ?> <?php disabled( ! $can_edit ); ?>><span class="thw-icon status-unexcused">U</span></label>
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

											$is_veg = isset( $vegetarian_map[ $name ] );
											$veg_icon = $is_veg ? ' <span title="Vegetarier" style="color:#28a745; cursor:help;">&#127811;</span>' : '';
										?>
										<tr>
											<td>
												<?php echo esc_html( $name ) . $veg_icon; ?>
												<?php echo $extra_info; ?>
											</td>
											<td style="text-align:left; white-space:nowrap;">
												<label class="thw-radio-wrapper" title="Anwesend"><input type="radio" name="attendance_manual[<?php echo esc_attr( $name ); ?>]" value="present" <?php checked( $status, 'present' ); ?> <?php disabled( ! $can_edit ); ?>><span class="thw-icon status-present">&#10004;</span></label>
												<label class="thw-radio-wrapper" title="Entschuldigt"><input type="radio" name="attendance_manual[<?php echo esc_attr( $name ); ?>]" value="leave" <?php checked( $status, 'leave' ); ?> <?php disabled( ! $can_edit ); ?>><span class="thw-icon status-leave">E</span></label>
												<label class="thw-radio-wrapper" title="Krank"><input type="radio" name="attendance_manual[<?php echo esc_attr( $name ); ?>]" value="sick" <?php checked( $status, 'sick' ); ?> <?php disabled( ! $can_edit ); ?>><span class="thw-icon status-sick">K</span></label>
												<label class="thw-radio-wrapper" title="Unentschuldigt"><input type="radio" name="attendance_manual[<?php echo esc_attr( $name ); ?>]" value="unexcused" <?php checked( $status, 'unexcused' ); ?> <?php disabled( ! $can_edit ); ?>><span class="thw-icon status-unexcused">U</span></label>
												<?php if ( $can_edit ) : ?>
													<label class="thw-radio-wrapper" title="Löschen" style="vertical-align:top; margin-left:5px;"><input type="radio" name="attendance_manual[<?php echo esc_attr( $name ); ?>]" value="delete"><span class="thw-icon" style="color:#a00; border-color:#a00;">🗑</span></label>
												<?php endif; ?>
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
										toast.innerText = data.data || 'Fehler';
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