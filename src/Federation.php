<?php

namespace Manuelantunes\WpGraphqlFederations;

class Federation {

	public static function init() {
		$instance = new self();
		add_action( 'graphql_register_types', [ $instance, 'register_federation_types' ] );
		add_filter( 'graphql_schema_config', [ $instance, 'modify_schema_config' ] );
		add_action( 'graphql_register_types', [ $instance, 'add_federation_to_nodes' ], 100 );
	}

	public function get_federated_types() {
		$settings = get_option( 'wpgraphql_federation_settings', [] );
		$types = [];
		foreach ( $settings as $graphql_name => $config ) {
			if ( ! empty( $config['enabled'] ) ) {
				$types[$graphql_name] = $config;
			}
		}

		// Core federated types with correct kind/key.
		// These are force-merged on top of admin settings because the admin UI's
		// auto-discovery can classify types incorrectly (e.g. Post as kind=other),
		// which breaks entity resolution. Admin-configured fields like 'shareable',
		// 'fields', etc. are preserved — only kind/key/enabled are overridden.
		$core_defaults = [
			'User'     => [ 'enabled' => true, 'key' => 'id',         'kind' => 'user' ],
			'Post'     => [ 'enabled' => true, 'key' => 'databaseId', 'kind' => 'post_type' ],
			'UserRole' => [ 'enabled' => true, 'key' => 'id',         'kind' => 'user_role' ],
		];

		/**
		 * Filter the core federated type defaults.
		 * These are force-merged on top of admin settings to ensure correct kind/key.
		 * Return an empty array to disable all core defaults.
		 *
		 * @param array $core_defaults Associative array of GraphQL type name => config overrides.
		 */
		$core_defaults = apply_filters( 'wp_graphql_federation_default_types', $core_defaults );

		foreach ( $core_defaults as $name => $defaults ) {
			// Merge defaults ON TOP of settings — core kind/key always win,
			// but other admin fields (shareable, fields, etc.) are preserved.
			$types[$name] = array_merge( $types[$name] ?? [], $defaults );
		}

		/**
		 * Filter the federated types before they are used.
		 * Allows programmatic registration of federated types.
		 *
		 * @param array $types Associative array of GraphQL type name => config.
		 */
		$types = apply_filters( 'wp_graphql_federation_types', $types );

		return $types;
	}

	public function register_federation_types() {
		$federated_types = $this->get_federated_types();
		$type_names = array_keys( $federated_types );

		// Register scalars if not already registered
		if ( ! \WPGraphQL::get_type_registry()->get_type( '_Any' ) ) {
			register_graphql_scalar( '_Any', [
				'description' => 'The _Any scalar is used to pass representation objects to the _entities query.',
				'serialize' => function( $value ) { return $value; },
				'parseValue' => function( $value ) { return $value; },
				'parseLiteral' => function( $ast ) {
					return self::ast_to_value( $ast );
				},
			]);
		}

		if ( ! \WPGraphQL::get_type_registry()->get_type( '_FieldSet' ) ) {
			register_graphql_scalar( '_FieldSet', [
				'description' => 'The _FieldSet scalar is used to describe a set of fields for federation directives.',
				'serialize' => function( $value ) { return $value; },
				'parseValue' => function( $value ) { return $value; },
				'parseLiteral' => function( $ast ) { return $ast->value; },
			]);
		}

		// Register _Entity union if we have federated types
		if ( ! empty( $type_names ) ) {
			register_graphql_union_type( '_Entity', [
				'typeNames' => $type_names,
				'resolveType' => function( $type ) use ( $type_names ) {
					$resolved = null;

					// 1. Post models — derive type name from the post_type object
					if ( $type instanceof \WPGraphQL\Model\Post ) {
						$pt = get_post_type_object( $type->post_type );
						$resolved = $pt ? ucfirst( $pt->graphql_single_name ?? $pt->name ) : null;
					}
					// 2. Term models — derive type name from the taxonomy object
					elseif ( $type instanceof \WPGraphQL\Model\Term ) {
						$tax = get_taxonomy( $type->taxonomy );
						$resolved = $tax ? ($tax->graphql_single_name ?? $tax->name) : null;
					}
					// 3. Any other WPGraphQL Model — extract type name from class name
					//    e.g. WPGraphQL\Model\User → "User", WPGraphQL\Model\UserRole → "UserRole"
					elseif ( is_object( $type ) ) {
						$class = get_class( $type );
						$short = substr( $class, strrpos( $class, '\\' ) + 1 );
						// Only use it if it's actually in our federated types list
						if ( in_array( $short, $type_names, true ) ) {
							$resolved = $short;
						}
					}

					/**
					 * Filter the resolved GraphQL type name for a federated entity.
					 *
					 * @param string|null $resolved   The resolved type name.
					 * @param mixed       $type       The WPGraphQL model object.
					 * @param array       $type_names All registered federated type names.
					 */
					return apply_filters( 'wp_graphql_federation_resolve_type', $resolved, $type, $type_names );
				},
			]);
		}

		// Register _Service type
		register_graphql_object_type( '_Service', [
			'fields' => [
				'sdl' => [
					'type' => 'String',
					'resolve' => function() {
						return self::get_sdl();
					},
				],
			],
		]);

		add_filter( 'graphql_RootQuery_fields', [ $this, 'add_root_fields' ] );
	}

	public function add_root_fields( $fields ) {
		$fields['_service'] = [
			'type' => [ 'non_null' => '_Service' ],
			'description' => 'Apollo Federation service definition',
			'resolve' => function() {
				return [ 'sdl' => self::get_sdl() ];
			},
		];

		$fields['_entities'] = [
			'type' => [ 'non_null' => [ 'list_of' => '_Entity' ] ],
			'description' => 'Apollo Federation entities resolver',
			'args' => [
				'representations' => [
					'type' => [ 'non_null' => [ 'list_of' => [ 'non_null' => '_Any' ] ] ],
				],
			],
			'resolve' => function( $root, $args, $context, $info ) {
				$entities = [];
				if ( ! empty( $args['representations'] ) ) {
					foreach ( $args['representations'] as $rep ) {
						$entities[] = $this->resolve_entity( $rep, $context );
					}
				}
				return $entities;
			},
		];

		return $fields;
	}

	public function add_federation_to_nodes() {
		$federated_types = $this->get_federated_types();
		if ( empty( $federated_types ) ) {
			return;
		}

		// Apply type-level directives using the global filter
		add_filter( 'graphql_object_type_config', function( $type_config, $typename ) use ( $federated_types ) {
			if ( ! isset( $federated_types[$typename] ) ) {
				return $type_config;
			}

			error_log("WPGraphQL Federation: Applying directives to type: $typename");
			$config = $federated_types[$typename];
			$directives = $type_config['e_directives'] ?? [];

			if ( ! empty( $config['key'] ) ) {
				$directives[] = [ 'name' => 'key', 'args' => [ 'fields' => $config['key'] ] ];
			}

			if ( ! empty( $config['shareable'] ) ) {
				$directives[] = [ 'name' => 'shareable', 'args' => [] ];
			}

			if ( ! empty( $config['inaccessible'] ) ) {
				$directives[] = [ 'name' => 'inaccessible', 'args' => [] ];
			}

			if ( ! empty( $config['custom_directives'] ) ) {
				$customs = explode(' ', $config['custom_directives']);
				foreach ($customs as $custom) {
					if (strpos($custom, '@') === 0) {
						$directives[] = [ 'name' => substr($custom, 1), 'args' => [] ];
					}
				}
			}

			$type_config['e_directives'] = $directives;
			return $type_config;
		}, 20, 2);

		// Apply field-level directives using type-specific filters
		foreach ( $federated_types as $graphql_name => $config ) {
			if ( ! empty( $config['fields'] ) ) {
				add_filter( "graphql_{$graphql_name}_fields", function( $fields ) use ( $config ) {
					foreach ( $config['fields'] as $field_name => $field_config ) {
						if ( ! isset( $fields[$field_name] ) ) continue;

						$directives = $fields[$field_name]['e_directives'] ?? [];

						foreach ($field_config as $d_name => $d_data) {
							if ( empty($d_data['enabled']) ) continue;

							$args = [];
							if ( isset($d_data['val']) && !empty($d_data['val']) ) {
								$arg_map = [
									'requires' => 'fields',
									'provides' => 'fields',
									'override' => 'from',
									'tag' => 'name',
								];
								$arg_name = $arg_map[$d_name] ?? 'value';
								$args[$arg_name] = $d_data['val'];
							}

							$directives[] = [ 'name' => $d_name, 'args' => $args ];
						}

						if (!empty($directives)) {
							$fields[$field_name]['e_directives'] = $directives;
						}
					}
					return $fields;
				}, 20);
			}
		}
	}

	public function modify_schema_config( $config ) {
		return $config;
	}

	private function resolve_entity( $representation, $context ) {
		if ( ! isset( $representation['__typename'] ) ) {
			error_log('Federation: resolve_entity - no __typename');
			return null;
		}

		$typename = $representation['__typename'];
		$federated_types = $this->get_federated_types();

		if ( ! isset( $federated_types[$typename] ) ) {
			error_log("Federation: resolve_entity - type '$typename' not in federated_types: " . json_encode(array_keys($federated_types)));
			return null;
		}

		$config = $federated_types[$typename];
		$key_field = $config['key'] ?? 'id';

		if ( ! isset( $representation[$key_field] ) ) {
			error_log("Federation: resolve_entity - key '$key_field' not found in representation: " . json_encode($representation));
			return null;
		}

		$id = $representation[$key_field];

		if ( 'id' === $key_field ) {
			$id_components = \GraphQLRelay\Relay::fromGlobalId( $id );
			if ( ! $id_components ) return null;
			$database_id = $id_components['id'];
		} else {
			$database_id = $id;
		}

		error_log("Federation: resolve_entity - resolving $typename with $key_field=$database_id, kind={$config['kind']}");

		$kind = $config['kind'] ?? '';

		$loader_map = [
			'post_type' => 'post',
			'taxonomy'  => 'term',
			'term'      => 'term',
			'user'      => 'user',
			'comment'   => 'comment',
			'menu'      => 'menu',
			'menuitem'  => 'menu_item',
		];

		$loader_name = $loader_map[$kind] ?? $kind;

		/**
		 * Filter the loader name used to resolve a federated entity.
		 *
		 * @param string $loader_name  The WPGraphQL loader name.
		 * @param string $typename     The GraphQL type name.
		 * @param string $kind         The entity kind from settings.
		 * @param mixed  $database_id  The database ID being resolved.
		 * @param array  $representation The full representation from the gateway.
		 */
		$loader_name = apply_filters(
			'wp_graphql_federation_entity_loader',
			$loader_name, $typename, $kind, $database_id, $representation
		);

		try {
			$loader = $context->get_loader( $loader_name );
			if ( $loader ) {
				return $loader->load_deferred( $database_id );
			}
		} catch ( \Exception $e ) {
			error_log("Federation: resolve_entity error for '$typename' (loader: $loader_name): " . $e->getMessage());
			return null;
		}

		/**
		 * Last-resort filter: allow fully custom entity resolution.
		 *
		 * @param mixed|null $entity         The resolved entity (null by default).
		 * @param string     $typename       The GraphQL type name.
		 * @param mixed      $database_id    The database ID.
		 * @param array      $config         The federation config for this type.
		 * @param object     $context        The WPGraphQL AppContext.
		 * @param array      $representation The full representation.
		 */
		return apply_filters(
			'wp_graphql_federation_resolve_entity',
			null, $typename, $database_id, $config, $context, $representation
		);
	}

	public static function get_sdl() {
		$schema = \WPGraphQL::get_schema();
		if ( ! $schema ) return '';

		try {
			$sdl = \GraphQL\Utils\SchemaPrinter::doPrint( $schema );

			$instance = new self();
			$settings = $instance->get_federated_types();

			foreach ( $settings as $type => $config ) {
				$directives_str = '';

				if ( ! empty( $config['key'] ) ) {
					$directives_str .= ' @key(fields: "' . $config['key'] . '")';
				}
				if ( ! empty( $config['shareable'] ) ) {
					$directives_str .= ' @shareable';
				}
				if ( ! empty( $config['inaccessible'] ) ) {
					$directives_str .= ' @inaccessible';
				}

				if ( ! empty( $directives_str ) ) {
					$escaped_type = preg_quote( $type, '/' );
					$sdl = preg_replace_callback(
						"/type\s+{$escaped_type}\b([^\{]*)\{/",
						function ($matches) use ($type, $directives_str) {
							$signature = trim($matches[1]);
							if (strpos($signature, '@key') !== false) {
								return "type {$type} {$signature} {";
							}
							return "type {$type} {$signature}{$directives_str} {";
						},
						$sdl
					);
				}
			}

			$directive_defs = [
				'scalar _FieldSet',
				'directive @external on FIELD_DEFINITION',
				'directive @requires(fields: _FieldSet!) on FIELD_DEFINITION',
				'directive @provides(fields: _FieldSet!) on FIELD_DEFINITION',
				'directive @key(fields: _FieldSet!, resolvable: Boolean = true) repeatable on OBJECT | INTERFACE',
				'directive @link(url: String!, import: [String]) repeatable on SCHEMA',
				'directive @shareable on OBJECT | FIELD_DEFINITION',
				'directive @authenticated on FIELD_DEFINITION | OBJECT | INTERFACE | SCALAR | ENUM',
				'directive @requiresScopes(scopes: [[String!]!]!) on FIELD_DEFINITION | OBJECT | INTERFACE | SCALAR | ENUM',
				'directive @override(from: String!) on FIELD_DEFINITION',
				'directive @inaccessible on FIELD_DEFINITION | OBJECT | INTERFACE | SCALAR | ENUM | ARGUMENT_DEFINITION | INPUT_FIELD_DEFINITION',
				'directive @tag(name: String!) repeatable on FIELD_DEFINITION | OBJECT | INTERFACE | SCALAR | ENUM | ARGUMENT_DEFINITION | INPUT_FIELD_DEFINITION',
			];

			/**
			 * Filter the list of federation SDL directive definitions.
			 *
			 * @param string[] $directive_defs Array of SDL directive definition strings.
			 */
			$directive_defs = apply_filters( 'wp_graphql_federation_sdl_directives', $directive_defs );

			return $sdl . "\n" . implode("\n", $directive_defs) . "\n";

		} catch ( \Exception $e ) {
			return '';
		}
	}

	private static function ast_to_value( $ast ) {
		if ( $ast instanceof \GraphQL\Language\AST\ObjectValueNode ) {
			$result = [];
			foreach ( $ast->fields as $field ) {
				$result[ $field->name->value ] = self::ast_to_value( $field->value );
			}
			return $result;
		}
		if ( $ast instanceof \GraphQL\Language\AST\ListValueNode ) {
			$result = [];
			foreach ( $ast->values as $value ) {
				$result[] = self::ast_to_value( $value );
			}
			return $result;
		}
		if ( $ast instanceof \GraphQL\Language\AST\IntValueNode ) {
			return (int) $ast->value;
		}
		if ( $ast instanceof \GraphQL\Language\AST\FloatValueNode ) {
			return (float) $ast->value;
		}
		if ( $ast instanceof \GraphQL\Language\AST\BooleanValueNode ) {
			return $ast->value;
		}
		if ( $ast instanceof \GraphQL\Language\AST\NullValueNode ) {
			return null;
		}
		// StringValueNode, EnumValueNode, etc.
		return $ast->value;
	}
}
