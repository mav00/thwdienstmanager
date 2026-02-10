<?php
// Direktzugriff verhindern
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode Funktion für den Tab "Stammdaten"
 */
function thw_dm_stammdaten_shortcode() {
	global $wpdb;
	$table_units = $wpdb->prefix . 'thw_units';

	$page_url = add_query_arg( 'view', 'stammdaten', get_permalink() );
	$filter_unit_id = isset( $_REQUEST['filter_unit'] ) ? intval( $_REQUEST['filter_unit'] ) : 0;

	ob_start();

	// Einheiten laden
	$units = $wpdb->get_results( "SELECT * FROM $table_units ORDER BY zug ASC, bezeichnung ASC" );

	// Benutzer laden (ggf. gefiltert)
	$args = array();
	if ( $filter_unit_id > 0 ) {
		$args['meta_key'] = 'thw_unit_id';
		$args['meta_value'] = $filter_unit_id;
	}
	$users = get_users( $args );

	// Sortieren nach Nachname, Vorname
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
	<div class="thw-frontend-wrapper">
		<style>
			.thw-frontend-wrapper { max-width: 1000px; margin: 0 auto; font-family: sans-serif; }
			.thw-card { background: #f9f9f9; border: 1px solid #ddd; padding: 20px; margin-bottom: 20px; border-radius: 5px; }
			table.thw-table { width: 100%; border-collapse: collapse; margin-top: 10px; background: #fff; }
			table.thw-table th, table.thw-table td { text-align: left; padding: 10px; border-bottom: 1px solid #eee; }
			table.thw-table th { background: #eee; }
			select { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 3px; }
		</style>

		<!-- FILTER -->
		<div class="thw-card">
			<form method="get" action="<?php echo esc_url( $page_url ); ?>">
				<input type="hidden" name="view" value="stammdaten">
				<label style="font-weight:bold; display:block; margin-bottom:5px;">Einheit filtern:</label>
				<select name="filter_unit" onchange="this.form.submit()">
					<option value="0">-- Alle Einheiten --</option>
					<?php foreach ( $units as $u ) : ?>
						<option value="<?php echo $u->id; ?>" <?php selected( $filter_unit_id, $u->id ); ?>>
							<?php echo esc_html( $u->zug . ' - ' . $u->bezeichnung ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</form>
		</div>

		<!-- LISTE -->
		<div class="thw-card">
			<h3>Personenliste</h3>
			<?php if ( empty( $users ) ) : ?>
				<p>Keine Personen gefunden.</p>
			<?php else : ?>
				<table class="thw-table">
					<thead>
						<tr>
							<th>Name</th>
							<th>E-Mail</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $users as $user ) : 
							$lastname = $user->last_name;
							$firstname = $user->first_name;
							$fullname = ! empty( $lastname ) ? $lastname . ( ! empty( $firstname ) ? ', ' . $firstname : '' ) : $user->display_name;
						?>
						<tr>
							<td><?php echo esc_html( $fullname ); ?></td>
							<td><a href="mailto:<?php echo esc_attr( $user->user_email ); ?>"><?php echo esc_html( $user->user_email ); ?></a></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	</div>
	<?php
	return ob_get_clean();
}