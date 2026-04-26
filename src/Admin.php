<?php

namespace Manuelantunes\WpGraphqlFederations;

class Admin {

	public static function init() {
		$instance = new self();
		add_action( 'admin_menu', [ $instance, 'add_admin_menu' ] );
		add_action( 'admin_init', [ $instance, 'register_settings' ] );
	}

	public function add_admin_menu() {
		add_options_page(
			'WPGraphQL Federation',
			'WPGraphQL Federation',
			'manage_options',
			'wpgraphql-federation',
			[ $this, 'render_settings_page' ]
		);
	}

	public function register_settings() {
		register_setting( 'wpgraphql_federation_group', 'wpgraphql_federation_settings' );
	}

	private function get_nodes_from_registry() {
		$nodes = [];

		if ( ! class_exists( '\WPGraphQL' ) ) {
			return [];
		}

		// Force schema initialization
		$schema = \WPGraphQL::get_schema();
		$registry = \WPGraphQL::get_type_registry();
		
		// Find all types implementing the Node interface
		$node_interface = $registry->get_type('Node');
		$possible_nodes = [];

		if ( $node_interface instanceof \GraphQL\Type\Definition\InterfaceType ) {
			try {
				$possible_nodes = $schema->getPossibleTypes($node_interface);
				$names = array_map(function($t) { return $t->name; }, $possible_nodes);
				error_log('WPGraphQL Federation: Found ' . count($possible_nodes) . ' implementors of Node: ' . implode(', ', $names));
			} catch (\Exception $e) {
				error_log('WPGraphQL Federation: Schema-based discovery failed, falling back to manual reconstruction: ' . $e->getMessage());
				
				// Fallback: Use WPGraphQL's internal registries directly
				$all_pts = \WPGraphQL::get_allowed_post_types('objects');
				$all_taxs = \WPGraphQL::get_allowed_taxonomies('objects');
				
				foreach ($all_pts as $pt) {
					$type = $registry->get_type($pt->graphql_single_name);
					if ($type instanceof \GraphQL\Type\Definition\ObjectType) {
						$possible_nodes[] = $type;
					}
				}
				
				foreach ($all_taxs as $tax) {
					$type = $registry->get_type($tax->graphql_single_name);
					if ($type instanceof \GraphQL\Type\Definition\ObjectType) {
						$possible_nodes[] = $type;
					}
				}

				// Add core entities
				foreach (['User', 'Comment', 'Menu', 'MenuItem', 'Plugin', 'Theme', 'UserRole', 'CommentAuthor'] as $core) {
					$type = $registry->get_type($core);
					if ($type instanceof \GraphQL\Type\Definition\ObjectType) {
						$possible_nodes[] = $type;
					}
				}
			}
		} else {
			error_log('WPGraphQL Federation: Node interface NOT found in registry.');
		}

		// Use all possible nodes as our discovery base
		$all_types = !empty($possible_nodes) ? $possible_nodes : $registry->get_types();
		error_log('WPGraphQL Federation: Discovery started. Total types to scan: ' . count($all_types));

		foreach ( $all_types as $type ) {
			if ( ! $type instanceof \GraphQL\Type\Definition\ObjectType ) continue;

			$name = $type->name;
			if ( strpos($name, '_') === 0 || $name === 'RootQuery' || $name === 'RootMutation' ) continue;

			// Check if it's a Node
			$is_node = false;
			$interfaces = $type->getInterfaces();
			foreach ($interfaces as $interface) {
				if ('Node' === $interface->name) { $is_node = true; break; }
			}
			
			if (!$is_node) {
				try {
					$fields = $type->getFields();
					if (isset($fields['id'])) $is_node = true;
				} catch (\Exception $e) {}
			}

			if ($is_node) {
				$kind = 'Other Nodes';
				$internal_kind = 'other';
				$wp_name = strtolower($name);
				$label = $name;

				// Advanced grouping logic
				$all_pts = \WPGraphQL::get_allowed_post_types('objects');
				$all_taxs = \WPGraphQL::get_allowed_taxonomies('objects');
				
				$pt_map = [];
				foreach ($all_pts as $pt) { $pt_map[$pt->graphql_single_name] = $pt; }
				
				$tax_map = [];
				foreach ($all_taxs as $tax) { $tax_map[$tax->graphql_single_name] = $tax; }

				if ( isset($pt_map[$name]) ) {
					$kind = 'Post Types';
					$internal_kind = 'post_type';
					$wp_name = $pt_map[$name]->name;
					$label = $pt_map[$name]->label;
				} elseif ( isset($tax_map[$name]) ) {
					$kind = 'Taxonomies';
					$internal_kind = 'taxonomy';
					$wp_name = $tax_map[$name]->name;
					$label = $tax_map[$name]->label;
				} elseif ( in_array($name, ['User', 'Comment', 'Menu', 'MenuItem', 'Plugin', 'Theme', 'UserRole', 'CommentAuthor']) ) {
					$kind = 'Core Entities';
					$internal_kind = strtolower($name);
				}

				$nodes[$name] = [
					'label' => $label,
					'kind' => $kind,
					'internal_kind' => $internal_kind,
					'wp_name' => $wp_name,
					'type_object' => $type
				];
			}
		}

		error_log('WPGraphQL Federation: Discovery finished. Found nodes: ' . implode(', ', array_keys($nodes)));

		ksort($nodes);
		return $nodes;
	}

	private function render_field_directives($graphql_name, $field_name, $current_config) {
		$directives = [
			'shareable' => [ 'label' => '@shareable', 'has_args' => false ],
			'external' => [ 'label' => '@external', 'has_args' => false ],
			'inaccessible' => [ 'label' => '@inaccessible', 'has_args' => false ],
			'requires' => [ 'label' => '@requires', 'has_args' => true, 'arg_name' => 'fields', 'placeholder' => 'id' ],
			'provides' => [ 'label' => '@provides', 'has_args' => true, 'arg_name' => 'fields', 'placeholder' => 'id' ],
			'override' => [ 'label' => '@override', 'has_args' => true, 'arg_name' => 'from', 'placeholder' => 'subgraph' ],
			'tag' => [ 'label' => '@tag', 'has_args' => true, 'arg_name' => 'name', 'placeholder' => 'team-a' ],
		];

		$output = '<div class="field-directives-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 10px; padding: 10px; background: #f9f9f9; border: 1px solid #ddd; margin-bottom: 5px; border-radius: 4px;">';
		
		foreach ($directives as $key => $info) {
			$enabled = !empty($current_config[$key]['enabled']);
			$val = $current_config[$key]['val'] ?? '';
			
			$output .= '<div class="directive-item">';
			$output .= sprintf(
				'<label style="font-weight:bold;"><input type="checkbox" name="wpgraphql_federation_settings[%s][fields][%s][%s][enabled]" value="1" %s /> %s</label>',
				esc_attr($graphql_name),
				esc_attr($field_name),
				esc_attr($key),
				checked($enabled, true, false),
				esc_html($info['label'])
			);
			
			if ($info['has_args']) {
				$output .= sprintf(
					'<input type="text" style="width:100%%; font-size:11px; margin-top:4px;" name="wpgraphql_federation_settings[%s][fields][%s][%s][val]" value="%s" placeholder="%s" />',
					esc_attr($graphql_name),
					esc_attr($field_name),
					esc_attr($key),
					esc_attr($val),
					esc_attr($info['arg_name'] . ': ' . $info['placeholder'])
				);
			}
			$output .= '</div>';
		}
		
		$output .= '</div>';
		return $output;
	}

	public function render_settings_page() {
		$settings = get_option( 'wpgraphql_federation_settings', [] );
		$nodes = $this->get_nodes_from_registry();
		$registry = class_exists('\WPGraphQL') ? \WPGraphQL::get_type_registry() : null;

		// Group nodes by kind
		$grouped_nodes = [];
		foreach ($nodes as $name => $node) {
			$grouped_nodes[$node['kind']][$name] = $node;
		}

		?>
		<div class="wrap">
			<h1>WPGraphQL Federation Settings</h1>
			<p>Configure Apollo Federation v2 directives for your GraphQL types and fields.</p>
			
			<?php if (empty($nodes)) : ?>
				<div class="notice notice-error"><p>No GraphQL nodes found. Please ensure WPGraphQL is active and configured correctly.</p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'wpgraphql_federation_group' ); ?>
				
				<style>
					.fed-group-title { margin: 30px 0 15px; border-bottom: 2px solid #2271b1; padding-bottom: 5px; color: #2271b1; }
					.fed-card { background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); margin-bottom: 15px; padding: 0; border-radius: 4px; overflow: hidden; }
					.fed-card-header { padding: 12px 15px; border-bottom: 1px solid #eee; display: flex; align-items: center; justify-content: space-between; background: #f8f9fa; }
					.fed-card-content { padding: 15px; }
					.fed-fields-toggle { cursor: pointer; color: #2271b1; text-decoration: underline; font-size: 13px; font-weight: 600; }
					.fed-fields-container { margin-top: 15px; border-top: 1px dashed #ccc; padding-top: 15px; display: none; }
					.field-row { margin-bottom: 15px; border-left: 4px solid #2271b1; padding-left: 15px; }
					.field-name { font-weight: 600; margin-bottom: 8px; display: block; font-size: 14px; }
					.fed-type-label { font-size: 15px; font-weight: 700; margin-left: 10px; }
					.fed-type-name { color: #666; margin-left: 8px; font-family: monospace; font-size: 12px; }
				</style>

				<?php foreach ($grouped_nodes as $kind_label => $kind_nodes) : ?>
					<h2 class="fed-group-title"><?php echo esc_html($kind_label); ?></h2>
					
					<?php foreach ( $kind_nodes as $graphql_name => $node ) : 
						$config = $settings[$graphql_name] ?? [];
						$enabled = !empty($config['enabled']);
						$key = $config['key'] ?? 'id';
						$field_settings = $config['fields'] ?? [];
					?>
						<div class="fed-card">
							<div class="fed-card-header">
								<div>
									<input type="checkbox" name="wpgraphql_federation_settings[<?php echo esc_attr($graphql_name); ?>][enabled]" value="1" <?php checked($enabled); ?> />
									<span class="fed-type-label"><?php echo esc_html( $node['label'] ); ?></span>
									<span class="fed-type-name">(<?php echo esc_html( $graphql_name ); ?>)</span>
									
									<input type="hidden" name="wpgraphql_federation_settings[<?php echo esc_attr($graphql_name); ?>][kind]" value="<?php echo esc_attr($node['internal_kind']); ?>" />
									<input type="hidden" name="wpgraphql_federation_settings[<?php echo esc_attr($graphql_name); ?>][wp_name]" value="<?php echo esc_attr($node['wp_name']); ?>" />
								</div>
								<div class="fed-fields-toggle" onclick="toggleFields('<?php echo esc_attr($graphql_name); ?>')">Manage Fields ▼</div>
							</div>
							<div class="fed-card-content">
								<div style="display: flex; gap: 40px; align-items: center;">
									<div>
										<label><strong>@key fields:</strong></label>
										<input type="text" style="margin-left:10px;" name="wpgraphql_federation_settings[<?php echo esc_attr($graphql_name); ?>][key]" value="<?php echo esc_attr($key); ?>" />
									</div>
									<div>
										<label><strong>@shareable:</strong></label>
										<input type="checkbox" style="margin-left:10px;" name="wpgraphql_federation_settings[<?php echo esc_attr($graphql_name); ?>][shareable]" value="1" <?php checked(!empty($config['shareable'])); ?> />
									</div>
								</div>

								<div id="fields-<?php echo esc_attr($graphql_name); ?>" class="fed-fields-container">
									<h4 style="margin-bottom:15px;">Field-level Directives</h4>
									<?php 
										if ($registry) {
											$type = $registry->get_type($graphql_name);
											if ($type instanceof \GraphQL\Type\Definition\ObjectType) {
												$fields = $type->getFields();
												foreach ($fields as $field_name => $field) {
													echo '<div class="field-row">';
													echo '<span class="field-name">' . esc_html($field_name) . ' <small style="font-weight:normal; color:#666;">: ' . esc_html($field->getType()) . '</small></span>';
													echo $this->render_field_directives($graphql_name, $field_name, $field_settings[$field_name] ?? []);
													echo '</div>';
												}
											} else {
												echo '<p style="color:#d63638;">Fields not found in registry for <strong>' . esc_html($graphql_name) . '</strong>.</p>';
											}
										}
									?>
								</div>
							</div>
						</div>
					<?php endforeach; ?>
				<?php endforeach; ?>

				<?php submit_button(); ?>
			</form>
		</div>

		<script>
			function toggleFields(name) {
				var container = document.getElementById('fields-' + name);
				if (container.style.display === 'none' || container.style.display === '') {
					container.style.display = 'block';
				} else {
					container.style.display = 'none';
				}
			}
		</script>
		<?php
	}
}
