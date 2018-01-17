<?php

$mapsID = 'maps-'. uniqid();

if ( stristr($settings->markers_type, 'automatic') ){
		
	$settings->posts_per_page = -1; //We want all posts.
	
	$query = FLBuilderLoop::query( $settings );
	
	//Override the $settings->markers object values.
	$settings->markers = array();
	
	if ( $query->have_posts() ) {
			$coords_updated = 0;
	    while ( $query->have_posts() ) {
				
	        $query->the_post();
					$postid = get_the_ID();
					$address = get_post_meta( $postid, $settings->address_cf, true );
					
					$lat_field_name = (empty($settings->lat_cf))? 'bbgmap_lat' : $settings->lat_cf;
					$lng_field_name = (empty($settings->lng_cf))? 'bbgmap_lng' : $settings->lng_cf;
					
					$lat = get_post_meta( $postid, $lat_field_name, true );
					$lng = get_post_meta( $postid, $lng_field_name, true );
										
					$markerdata = array(
						'title' => get_the_title(),
						'marker' => $settings->marker_icon,
						'content' => get_the_content(),
					);
					
					if ( empty( $lat ) || empty( $lng ) ){
					
						/*
						 * G Maps Geocoder has a maximum 20 per second request limit
						 * So we grab as many as we can on each page load.
						 */
						if ( ! empty( $address ) && $coords_updated < 20 ){
							$new_coords = TPgMapModule::getLatLong($address);
							
							$coords_updated++;
							
							//Save for Javascript output
							$markerdata['lat'] = $new_coords['lat'];
							$markerdata['lng'] = $new_coords['lng'];
							
							//Save to DB
							if ( ! add_post_meta( $postid, $lat_field_name, $new_coords['lat'], true ) ) { 
   							update_post_meta( $postid, $lat_field_name, $new_coords['lat'] );
							}
							if ( ! add_post_meta( $postid, $lng_field_name, $new_coords['lng'], true ) ) { 
   							update_post_meta( $postid, $lng_field_name, $new_coords['lng'] );
							}
							
						}
						
					}
					else{ //use stored coords
						$markerdata['lat'] = $lat;
						$markerdata['lng'] = $lng;
					}
						
					$settings->markers[] = (object) $markerdata;
					
	    }
	}
	wp_reset_postdata();

}

$data = array(
	'markers'                   => $settings->markers,
	'zoom'                      => $settings->zoom,
	'height'                    => $settings->height .'px',
);

$controls = array(
	'overviewMapControl'        => 'false',
	'rotateControl'             => 'false',
	'scaleControl'              => 'false',
	'scrollwheel'               => 'false',
	'streetViewControl'         => 'false',
	'width'                     => '100%',
);

// Filter to change Google maps controls
if( has_filter('bbgmap_map_controls') ) {
	$controls = apply_filters( 'bbgmap_map_controls', $controls );
}

$atts = array_merge( $data, $controls );

// do_shortcode on all content field
if( $atts['markers'] && count($atts['markers']) > 0 ) {
	foreach ( $atts['markers'] as $id => $marker) {
		if( isset($atts['markers'][$id]->content) ) {
			$atts['markers'][$id]->content = do_shortcode( $atts['markers'][$id]->content );
		}
	}
}



if( !isset( $atts['markers'] ) || ( empty(  $atts['markers'][0]->lat ) || empty(  $atts['markers'][0]->lng ) ) ) {
	?>
	<div class="alert alert-danger" role="alert"><?php _e('Add a marker to see the map', 'bbgmap'); ?></div>
	<?php
	return;
}
?>

<style>#<?php echo esc_html( $mapsID ); ?> img {max-width: initial;}</style>
<script>
	var $j = jQuery.noConflict();
	var gmarkers = [];

	$j( document ).ready(function($) {

		<?php
		if( has_filter('bbgmap_map_style') ) {
			// Filter to change Google maps style
			$map_style = apply_filters( 'bbgmap_map_style', $map_style );
		}else if( $settings->map_style ) {
			$map_style = trim(stripslashes(json_encode($settings->map_style)), '"');
		}else{
			$map_style = '[{"featureType":"landscape","stylers":[{"saturation":-100},{"lightness":65},{"visibility":"on"}]},{"featureType":"poi","stylers":[{"saturation":-100},{"lightness":51},{"visibility":"simplified"}]},{"featureType":"road.highway","stylers":[{"saturation":-100},{"visibility":"simplified"}]},{"featureType":"road.arterial","stylers":[{"saturation":-100},{"lightness":30},{"visibility":"on"}]},{"featureType":"road.local","stylers":[{"saturation":-100},{"lightness":40},{"visibility":"on"}]},{"featureType":"transit","stylers":[{"saturation":-100},{"visibility":"simplified"}]},{"featureType":"administrative.province","stylers":[{"visibility":"off"}]},{"featureType":"water","elementType":"labels","stylers":[{"visibility":"on"},{"lightness":-25},{"saturation":-100}]},{"featureType":"water","elementType":"geometry","stylers":[{"hue":"#ffff00"},{"lightness":-25},{"saturation":-97}]}]';
		}

		$bbgmap_map_default_icon = TP_BB_GMAP_URL .'tpbbgmap/assets/images/marker.png';
		// Filter to change Google maps defaut icon
		if( has_filter('bbgmap_map_default_icon') ) {
			$bbgmap_map_default_icon = apply_filters( 'bbgmap_map_default_icon', $bbgmap_map_default_icon );
		}
		?>



		var styles = <?php echo $map_style; ?>;

		var styledMap = new google.maps.StyledMapType( styles, {name: "<?php echo __('Custom', 'bbgmap'); ?>"} );

		//var latlng = new google.maps.LatLng(<?php echo $atts['markers'][0]->lat; ?>,<?php echo $atts['markers'][0]->lng; ?>); // Paris
		var latlng = new google.maps.LatLng(0,0); //we'll re-center after loading anyway.
		
		var geocoder = new google.maps.Geocoder();
		
		var data = <?php echo json_encode( array( 'markers' => $atts['markers'] ) ); ?>;

		<?php if( count( $atts['markers'] ) > 1 ): ?>
			var bounds = true;
		<?php else: ?>
			var bounds = false;
		<?php endif; ?>

		// We need to bind the map with the "init" event otherwise bounds will be null
		$('#<?php echo esc_html( $mapsID ); ?>')
			.gmap({
				zoom:					<?php echo esc_js( $atts['zoom'] ); ?>,
				center:					latlng,
				overviewMapControl:		<?php echo esc_js( $atts['overviewMapControl'] ); ?>,
				rotateControl:			<?php echo esc_js( $atts['rotateControl'] ); ?>,
				scaleControl:			<?php echo esc_js( $atts['scaleControl'] ); ?>,
				scrollwheel:			<?php echo esc_js( $atts['scrollwheel'] ); ?>,
				streetViewControl:		<?php echo esc_js( $atts['streetViewControl'] ); ?>,
				mapTypeControlOptions:	{
					mapTypeIds: [google.maps.MapTypeId.SATELLITE, 'map_style']
				}
			})
			.bind('init', function(evt, map) {

				//Associate the styled map with the MapTypeId and set it to display.
				map.mapTypes.set('map_style', styledMap);
				map.setMapTypeId('map_style');

				$.each( data.markers, function(i, marker)
				{
					if( marker.marker ) {
						var icon = marker.marker_src;
					} else {
						var icon = '<?php echo esc_js( $bbgmap_map_defaut_icon ); ?>';
					}
													
					var m = $('#<?php echo esc_js( $mapsID ); ?>').gmap('addMarker', {
						'position': new google.maps.LatLng( marker.lat, marker.lng ),
						'icon': icon,
						'bounds': bounds
					}).click(function() {
						if(marker.content)
						{
							$('#<?php echo esc_js( $mapsID ); ?>').gmap('openInfoWindow', { content : the_content( marker ) }, this);
						}
					})[0];
					
				});

				$('#<?php echo esc_js( $mapsID ); ?>').gmap('set', 'MarkerClusterer', new MarkerClusterer(map, $(this).gmap('get', 'markers')));

			});
	});
</script>
<div class="gmap" id="<?php echo esc_html( $mapsID ); ?>" style="width: <?php echo $atts['width']; ?>; height: <?php echo $atts['height']; ?>;"></div>