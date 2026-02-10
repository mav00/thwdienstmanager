<?php
// Direktzugriff verhindern
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode [thw_service_details]
 * Zeigt ein Formular im Frontend an, um Details zu einem Dienst zu erfassen.
 */
function thw_dm_frontend_shortcode() {
	global $wpdb;
	$table_services = $wpdb->prefix . 'thw_services';
	$table_units    = $wpdb->prefix . 'thw_units';
	$table_details  = $wpdb->prefix . 'thw_service_details';

	// Puffer starten, um Ausgabe zurückzugeben
	ob_start();

	// 1. Dienst auswählen oder aus Post-Daten holen
	$selected_service_id = isset( $_REQUEST['service_id'] ) ? intval( $_REQUEST['service_id'] ) : 0;

	// --- SPEICHERN LOGIK ---
	if ( isset( $_POST['thw_dm_save_details_nonce'] ) && wp_verify_nonce( $_POST['thw_dm_save_details_nonce'], 'save_service_details' ) ) {
		if ( $selected_service_id > 0 && isset( $_POST['details'] ) && is_array( $_POST['details'] ) ) {
			
			// Zuerst alte Einträge für diesen Dienst löschen (Clean Slate Ansatz)
			// Man könnte dies verfeinern, aber für dieses Szenario ist es am sichersten, um Duplikate zu vermeiden.
			$wpdb->delete( $table_details, array( 'service_id' => $selected_service_id ) );

			// Neue Einträge speichern
			foreach ( $_POST['details'] as $unit_id => $entries ) {
				if ( is_array( $entries ) ) {
					foreach ( $entries as $entry ) {
						// Leere Zeilen überspringen (wenn Thema leer ist)
						if ( empty( trim( $entry['topic'] ) ) ) continue;

						$wpdb->insert(
							$table_details,
							array(
								'service_id' => $selected_service_id,
								'unit_id'    => intval( $unit_id ),
								'topic'      => sanitize_textarea_field( $entry['topic'] ),
								'duration'   => intval( $entry['duration'] ),
								'goal'       => sanitize_textarea_field( $entry['goal'] ),
								'section'    => sanitize_text_field( $entry['section'] ),
							)
						);
					}
				}
			}
			echo '<div class="thw-message success">Dienstplan erfolgreich gespeichert.</div>';
		}
	}

	// --- DATEN LADEN ---
	// Alle Dienste für das Dropdown laden (chronologisch)
	$services = $wpdb->get_results( "SELECT * FROM $table_services ORDER BY service_date ASC" );

	?>
	<div class="thw-frontend-wrapper">
		<style>
			.thw-frontend-wrapper { max-width: 1000px; margin: 0 auto; font-family: sans-serif; }
			.thw-card { background: #f9f9f9; border: 1px solid #ddd; padding: 20px; margin-bottom: 20px; border-radius: 5px; }
			.thw-unit-section { background: #fff; border: 1px solid #eee; padding: 15px; margin-bottom: 15px; border-left: 5px solid #003399; }
			.thw-entry-row { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 10px; padding-bottom: 10px; border-bottom: 1px dashed #eee; }
			.thw-field { flex: 1; min-width: 150px; }
			.thw-field label { display: block; font-size: 0.85em; margin-bottom: 3px; font-weight: bold; }
			.thw-field input, .thw-field textarea { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 3px; box-sizing: border-box; }
			.thw-btn { background: #003399; color: #fff; border: none; padding: 8px 15px; cursor: pointer; border-radius: 3px; }
			.thw-btn:hover { background: #002266; }
			.thw-btn.add-row { background: #eee; color: #333; border: 1px solid #ccc; font-size: 0.9em; }
			.thw-btn.add-row:hover { background: #ddd; }
			.thw-message { padding: 10px; margin-bottom: 20px; border-radius: 3px; }
			.thw-message.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
			
			/* Mobile Optimierung */
			@media (max-width: 600px) {
				.thw-entry-row { flex-direction: column; align-items: stretch; }
				.thw-field { flex: 1 1 100% !important; width: 100%; }
			}
		</style>

		<!-- AUSWAHL DES DIENSTES -->
		<div class="thw-card">
			<form method="get" action="<?php echo esc_url( add_query_arg( 'view', 'services', get_permalink() ) ); ?>">
				<input type="hidden" name="view" value="services">
				<label for="service_select"><strong>Dienst auswählen:</strong></label>
				<select name="service_id" id="service_select" onchange="this.form.submit()">
					<option value="">-- Bitte wählen --</option>
					<?php foreach ( $services as $s ) : ?>
						<option value="<?php echo $s->id; ?>" <?php selected( $selected_service_id, $s->id ); ?>>
							<?php echo date_i18n( 'd.m.Y', strtotime( $s->service_date ) ) . ' - ' . esc_html( $s->name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<noscript><button type="submit" class="thw-btn">Laden</button></noscript>
			</form>
		</div>

		<?php if ( $selected_service_id > 0 ) : 
			$current_service = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_services WHERE id = %d", $selected_service_id ) );
			
			// Status-Logik
			$service_status = isset( $current_service->status ) ? $current_service->status : 'open';
			$is_admin = current_user_can( 'administrator' );
			
			// Status ändern (Nur Admin)
			if ( isset( $_POST['thw_dm_status_action'] ) && $is_admin && check_admin_referer( 'thw_dm_status_nonce' ) ) {
				$new_status = $_POST['thw_dm_status_action'] === 'close' ? 'closed' : 'open';
				$wpdb->update( $table_services, array( 'status' => $new_status ), array( 'id' => $selected_service_id ) );
				$service_status = $new_status;
				echo '<div class="thw-message success">Status geändert: ' . ($new_status == 'closed' ? 'Abgeschlossen' : 'Geöffnet') . '</div>';
			}

			$can_edit = $is_admin || $service_status !== 'closed';

			// Einheiten dieses Dienstes ermitteln
			$assigned_unit_ids = maybe_unserialize( $current_service->unit_ids );
			if ( ! is_array( $assigned_unit_ids ) || empty( $assigned_unit_ids ) ) {
				echo '<p>Diesem Dienst sind keine Einheiten zugeordnet.</p>';
			} else {
				// Details laden, falls vorhanden (Editier-Modus)
				$existing_details = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table_details WHERE service_id = %d", $selected_service_id ) );
				
				// Details nach Unit ID gruppieren
				$details_by_unit = [];
				foreach ( $existing_details as $d ) {
					$details_by_unit[ $d->unit_id ][] = $d;
				}
				?>

				<?php if ( $is_admin ) : ?>
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
					<div class="thw-message error">Dieser Dienst wurde vom Administrator abgeschlossen. Bearbeitung nicht mehr möglich.</div>
				<?php endif; ?>

				<form method="post">
					<?php wp_nonce_field( 'save_service_details', 'thw_dm_save_details_nonce' ); ?>
					<input type="hidden" name="service_id" value="<?php echo $selected_service_id; ?>">

					<?php foreach ( $assigned_unit_ids as $uid ) : 
						$unit = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_units WHERE id = %d", $uid ) );
						if ( ! $unit ) continue;
						
						// Vorhandene Einträge oder ein leerer Eintrag als Start
						$unit_entries = isset( $details_by_unit[ $uid ] ) ? $details_by_unit[ $uid ] : array();
						if ( empty( $unit_entries ) ) {
							// Ein leeres Objekt für das Template erzeugen
							$empty_obj = new stdClass();
							$empty_obj->topic = ''; $empty_obj->duration = ''; $empty_obj->goal = ''; $empty_obj->section = '';
							$unit_entries[] = $empty_obj;
						}
						?>
						
						<div class="thw-unit-section" id="unit-wrapper-<?php echo $uid; ?>">
							<h3><?php echo esc_html( $unit->zug . ' - ' . $unit->bezeichnung ); ?></h3>
							
							<div class="entries-container" data-unit="<?php echo $uid; ?>">
								<?php 
								$row_index = 0;
								foreach ( $unit_entries as $entry ) : 
								?>
									<div class="thw-entry-row">
										<div class="thw-field" style="flex-basis: 100%;">
											<label>Ausbildungsthema</label>
											<textarea name="details[<?php echo $uid; ?>][<?php echo $row_index; ?>][topic]" rows="3" required placeholder="Was wird gemacht?" <?php disabled( ! $can_edit ); ?>><?php echo esc_textarea( $entry->topic ); ?></textarea>
										</div>
										<div class="thw-field" style="flex-basis: 100%;">
											<label>Ziel der Ausbildung</label>
											<textarea name="details[<?php echo $uid; ?>][<?php echo $row_index; ?>][goal]" rows="3" required placeholder="Lernziel" <?php disabled( ! $can_edit ); ?>><?php echo esc_textarea( $entry->goal ); ?></textarea>
										</div>
										<div class="thw-field" style="flex: 0 0 100px;">
											<label>Dauer (Min)</label>
											<input type="number" name="details[<?php echo $uid; ?>][<?php echo $row_index; ?>][duration]" value="<?php echo esc_attr( $entry->duration ); ?>" required <?php disabled( ! $can_edit ); ?>>
										</div>
										<div class="thw-field" style="flex: 1;">
											<label>Lernabschnitt (Optional)</label>
											<input type="text" name="details[<?php echo $uid; ?>][<?php echo $row_index; ?>][section]" value="<?php echo esc_attr( $entry->section ); ?>" <?php disabled( ! $can_edit ); ?>>
										</div>
									</div>
								<?php 
								$row_index++;
								endforeach; 
								?>
							</div>

							<?php if ( $can_edit ) : ?>
								<button type="button" class="thw-btn add-row" onclick="thwAddRow(<?php echo $uid; ?>)">+ Weitere Zeile hinzufügen</button>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>

					<?php if ( $can_edit ) : ?>
						<div style="margin-top: 20px; text-align: right;">
							<button type="submit" class="thw-btn" style="font-size: 1.2em;">Dienstplan Speichern</button>
						</div>
					<?php endif; ?>
				</form>

				<!-- JavaScript Template für neue Zeilen -->
				<script>
					function thwAddRow(unitId) {
						var container = document.querySelector('.entries-container[data-unit="' + unitId + '"]');
						var index = container.querySelectorAll('.thw-entry-row').length;
						
						var html = `
							<div class="thw-entry-row">
								<div class="thw-field" style="flex-basis: 100%;">
									<label>Ausbildungsthema</label>
									<textarea name="details[${unitId}][${index}][topic]" rows="3" required placeholder="Was wird gemacht?"></textarea>
								</div>
								<div class="thw-field" style="flex-basis: 100%;">
									<label>Ziel der Ausbildung</label>
									<textarea name="details[${unitId}][${index}][goal]" rows="3" required placeholder="Lernziel"></textarea>
								</div>
								<div class="thw-field" style="flex: 0 0 100px;">
									<label>Dauer (Min)</label>
									<input type="number" name="details[${unitId}][${index}][duration]" required>
								</div>
								<div class="thw-field" style="flex: 1;">
									<label>Lernabschnitt (Optional)</label>
									<input type="text" name="details[${unitId}][${index}][section]">
								</div>
								<div class="thw-field" style="flex: 0 0 30px; align-self: center;">
									<button type="button" onclick="this.parentElement.parentElement.remove()" style="color:red; border:none; background:none; cursor:pointer; font-weight:bold;">X</button>
								</div>
							</div>
						`;
						
						// HTML einfügen
						var tempDiv = document.createElement('div');
						tempDiv.innerHTML = html;
						container.appendChild(tempDiv.firstElementChild);
					}
				</script>

			<?php } ?>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}