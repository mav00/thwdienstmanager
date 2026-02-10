<?php
// Direktzugriff verhindern
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode Funktion für den Tab "Auswertungen"
 */
function thw_dm_evaluation_shortcode() {
	global $wpdb;
	$table_services = $wpdb->prefix . 'thw_services';
	$table_units    = $wpdb->prefix . 'thw_units';

	$page_url = add_query_arg( 'view', 'evaluation', get_permalink() );
	
	// Filter Parameter
	$current_year = date( 'Y' );
	$filter_year  = isset( $_REQUEST['filter_year'] ) ? intval( $_REQUEST['filter_year'] ) : $current_year;
	$filter_zug   = isset( $_REQUEST['filter_zug'] ) ? sanitize_text_field( $_REQUEST['filter_zug'] ) : '';

	ob_start();

	// 1. Verfügbare Jahre laden
	$years = $wpdb->get_col( "SELECT DISTINCT YEAR(service_date) FROM $table_services ORDER BY service_date DESC" );
	if ( empty( $years ) ) {
		$years = array( $current_year );
	}

	// 2. Verfügbare Züge laden
	$zuege = $wpdb->get_col( "SELECT DISTINCT zug FROM $table_units ORDER BY zug ASC" );

	// Alle Einheiten laden (für Mapping und Sortierung)
	$all_units = $wpdb->get_results( "SELECT * FROM $table_units" );
	$unit_map = array();
	foreach ( $all_units as $u ) {
		$unit_map[ $u->id ] = $u->zug . ' - ' . $u->bezeichnung;
	}

	// 3. Dienste laden (gefiltert nach Jahr)
	$sql_services = "SELECT * FROM $table_services WHERE YEAR(service_date) = %d ORDER BY service_date ASC";
	$services_raw = $wpdb->get_results( $wpdb->prepare( $sql_services, $filter_year ) );

	// Dienste filtern, falls Zug gewählt (nur Dienste anzeigen, die für diesen Zug relevant sind)
	$services = array();
	$zug_unit_ids = array();
	
	if ( ! empty( $filter_zug ) ) {
		$zug_unit_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $table_units WHERE zug = %s", $filter_zug ) );
		
		foreach ( $services_raw as $s ) {
			$s_units = maybe_unserialize( $s->unit_ids );
			// Wenn der Dienst Einheiten dieses Zugs enthält, anzeigen
			if ( is_array( $s_units ) && array_intersect( $s_units, $zug_unit_ids ) ) {
				$services[] = $s;
			}
		}
	} else {
		$services = $services_raw;
	}

	// 4. Benutzer laden
	$user_args = array();
	if ( ! empty( $filter_zug ) && ! empty( $zug_unit_ids ) ) {
		$user_args['meta_key']     = 'thw_unit_id';
		$user_args['meta_value']   = $zug_unit_ids;
		$user_args['meta_compare'] = 'IN';
	}
	$users = get_users( $user_args );

	// Einheitennamen an User anhängen (für Sortierung und Anzeige)
	foreach ( $users as $u ) {
		$uid = get_user_meta( $u->ID, 'thw_unit_id', true );
		$u->thw_unit_name = isset( $unit_map[ $uid ] ) ? $unit_map[ $uid ] : '';
	}

	// Sortieren nach Einheit, Nachname, Vorname
	usort( $users, function( $a, $b ) {
		$res_unit = strcasecmp( $a->thw_unit_name, $b->thw_unit_name );
		if ( $res_unit !== 0 ) return $res_unit;

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
	<div class="thw-frontend-wrapper">
		<style>
			.thw-eval-table th, .thw-eval-table td { text-align: center; vertical-align: middle; padding: 5px; border: 1px solid #ddd; font-size: 0.9em; white-space: nowrap; }
			.thw-eval-table th.rotate { height: 140px; white-space: nowrap; }
			.thw-eval-table th.rotate > div { transform: translate(0px, 50px) rotate(-90deg); width: 30px; }
			.thw-eval-table th.rotate > div > span { padding: 5px 10px; }
			.thw-status-present { color: #155724; background-color: #d4edda; font-weight: bold; }
			.thw-status-leave { color: #856404; background-color: #fff3cd; }
			.thw-status-sick { color: #856404; background-color: #fff3cd; }
			.thw-status-unexcused { color: #fff; background-color: #dc3545; }
			.thw-name-col { text-align: left !important; font-weight: bold; min-width: 150px; position: sticky; left: 0; background: #fff; z-index: 2; border-right: 2px solid #ddd !important; }
		</style>

		<!-- FILTER -->
		<div class="thw-card">
			<form method="get" action="<?php echo esc_url( $page_url ); ?>">
				<input type="hidden" name="view" value="evaluation">
				<div class="thw-flex">
					<div class="thw-col">
						<label for="filter_zug">Zug auswählen:</label>
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
						<label for="filter_year">Jahr auswählen:</label>
						<select name="filter_year" id="filter_year" onchange="this.form.submit()">
							<?php foreach ( $years as $y ) : ?>
								<option value="<?php echo esc_attr( $y ); ?>" <?php selected( $filter_year, $y ); ?>>
									<?php echo esc_html( $y ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>
			</form>
		</div>

		<!-- TABELLE -->
		<div class="thw-card" style="padding: 0; overflow: hidden;">
			<?php if ( empty( $services ) ) : ?>
				<div style="padding: 20px;">Keine Dienste für die gewählte Auswahl gefunden.</div>
			<?php elseif ( empty( $users ) ) : ?>
				<div style="padding: 20px;">Keine Helfer gefunden.</div>
			<?php else : ?>
				<div class="thw-table-wrapper" style="overflow-x: auto; max-width: 100%;">
					<table class="thw-eval-table" style="width: max-content; min-width: 100%; border-collapse: collapse;">
						<thead>
							<tr>
								<th class="thw-name-col" style="z-index: 3;">Name</th>
								<th style="min-width: 50px;">Summe</th>
								<?php foreach ( $services as $s ) : ?>
									<th class="rotate"><div><span>
										<?php echo date_i18n( 'd.m.', strtotime( $s->service_date ) ); ?>
										<br><small><?php echo esc_html( substr( $s->name, 0, 15 ) ); ?></small>
									</span></div></th>
								<?php endforeach; ?>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $users as $user ) : 
								$lastname = $user->last_name;
								$firstname = $user->first_name;
								$fullname = ! empty( $lastname ) ? $lastname . ( ! empty( $firstname ) ? ', ' . $firstname : '' ) : $user->display_name;
								
								$attendance_count = 0;
							?>
								<tr>
									<td class="thw-name-col">
										<?php echo esc_html( $fullname ); ?>
										<?php if ( ! empty( $user->thw_unit_name ) ) : ?>
											<br><span style="font-size:0.8em; color:#666; font-weight:normal;"><?php echo esc_html( $user->thw_unit_name ); ?></span>
										<?php endif; ?>
									</td>
									
									<!-- Platzhalter für Summe (wird später berechnet, aber hier gerendert) -->
									<?php ob_start(); ?>
									
									<?php foreach ( $services as $s ) : 
										$attendance_data = maybe_unserialize( $s->attendance );
										$status = isset( $attendance_data[ $fullname ] ) ? $attendance_data[ $fullname ] : '';
										
										$cell_class = '';
										$cell_content = '-';

										if ( $status === 'present' ) {
											$cell_class = 'thw-status-present';
											$cell_content = 'X';
											$attendance_count++;
										} elseif ( $status === 'leave' ) {
											$cell_class = 'thw-status-leave';
											$cell_content = 'E';
										} elseif ( $status === 'sick' ) {
											$cell_class = 'thw-status-sick';
											$cell_content = 'K';
										} elseif ( $status === 'unexcused' ) {
											$cell_class = 'thw-status-unexcused';
											$cell_content = 'U';
										}
									?>
										<td class="<?php echo $cell_class; ?>"><?php echo $cell_content; ?></td>
									<?php endforeach; ?>
									
									<?php $row_cells = ob_get_clean(); ?>
									
									<td><strong><?php echo $attendance_count; ?></strong></td>
									<?php echo $row_cells; ?>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<div style="padding: 10px; font-size: 0.85em; color: #666; background: #f9f9f9; border-top: 1px solid #ddd;">
					<strong>Legende:</strong> 
					<span style="display:inline-block; width:15px; text-align:center; background:#d4edda; color:#155724; font-weight:bold; border:1px solid #ccc; margin-left:10px;">X</span> Anwesend
					<span style="display:inline-block; width:15px; text-align:center; background:#fff3cd; color:#856404; border:1px solid #ccc; margin-left:10px;">E</span> Entschuldigt
					<span style="display:inline-block; width:15px; text-align:center; background:#fff3cd; color:#856404; border:1px solid #ccc; margin-left:10px;">K</span> Krank
					<span style="display:inline-block; width:15px; text-align:center; background:#dc3545; color:#fff; border:1px solid #ccc; margin-left:10px;">U</span> Unentschuldigt
				</div>
			<?php endif; ?>
		</div>
	</div>
	<?php
	return ob_get_clean();
}