<?php

namespace Manuelantunes\WpGraphqlFederations;

class Federation {

	public static function init() {
		$instance = new self();
		add_action( 'graphql_register_types', [ $instance, 'register_federation_types' ] );
		add_filter( 'graphql_schema_config', [ $instance, 'modify_schema_config' ] );
		add_action( 'graphql_register_types', [ $instance, 'add_federation_to_nodes' ], 100 );
	}

	private function get_federated_types() {
		$settings = get_option( 'wpgraphql_federation_settings', [] );
		$types = [];
		foreach ( $settings as $graphql_name => $config ) {
			if ( ! empty( $config['enabled'] ) ) {
				$types[$graphql_name] = $config;
			}
		}
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
				'parseLiteral' => function( $ast ) { return $ast->value; },
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
				'resolveType' => function( $type ) {
					if ( $type instanceof \WPGraphQL\Model\Post ) {
						$pt = get_post_type_object( $type->post_type );
						return $pt->graphql_single_name ?? $pt->name;
					}
					if ( $type instanceof \WPGraphQL\Model\Term ) {
						$tax = get_taxonomy( $type->taxonomy );
						return $tax->graphql_single_name ?? $tax->name;
					}
					if ( $type instanceof \WPGraphQL\Model\User ) {
						return 'User';
					}
					if ( $type instanceof \WPGraphQL\Model\Comment ) {
						return 'Comment';
					}
					return null;
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
			return null;
		}

		$typename = $representation['__typename'];
		$federated_types = $this->get_federated_types();

		if ( ! isset( $federated_types[$typename] ) ) {
			return null;
		}

		$config = $federated_types[$typename];
		$key_field = $config['key'] ?? 'id';

		if ( ! isset( $representation[$key_field] ) ) {
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

		switch ( $config['kind'] ) {
			case 'post_type':
				return \WPGraphQL\Data\Loader::get_element( $database_id, 'post' );
			case 'taxonomy':
			case 'term':
				return \WPGraphQL\Data\Loader::get_element( $database_id, 'term' );
			case 'user':
				return \WPGraphQL\Data\Loader::get_element( $database_id, 'user' );
			case 'comment':
				return \WPGraphQL\Data\Loader::get_element( $database_id, 'comment' );
		}

		return null;
	}

	public static function get_sdl() {
		$schema = \WPGraphQL::get_schema();
		if ( ! $schema ) return '';

		try {
			$sdl = \GraphQL\Utils\SchemaPrinter::doPrint( $schema );

			$settings = get_option( 'wpgraphql_federation_settings', [] );

			foreach ( $settings as $type => $config ) {
				if ( empty( $config['enabled'] ) ) continue;

				$key = $config['key'] ?? 'id';

				// injeta @key no type
				$sdl = preg_replace_callback(
					"/type\s+{$type}\b([^\\{]*)\{/",
					function ($matches) use ($type, $key) {
						$signature = trim($matches[1]);
	
						if (strpos($signature, '@key') !== false) {
							return "type {$type} {$signature} {";
						}

						return "type {$type} {$signature} @key(fields: \"{$key}\") {";
					},  
					$sdl
				);
			}

			$federation_directives = '
	scalar _FieldSet

	directive @external on FIELD_DEFINITION
	directive @requires(fields: _FieldSet!) on FIELD_DEFINITION
	directive @provides(fields: _FieldSet!) on FIELD_DEFINITION
	directive @key(fields: _FieldSet!, resolvable: Boolean = true) repeatable on OBJECT | INTERFACE
	directive @link(url: String!, import: [String]) repeatable on SCHEMA
	directive @shareable on OBJECT | FIELD_DEFINITION
	directive @authenticated on FIELD_DEFINITION | OBJECT | INTERFACE | SCALAR | ENUM
	directive @requiresScopes(scopes: [[String!]!]!) on FIELD_DEFINITION | OBJECT | INTERFACE | SCALAR | ENUM
	directive @override(from: String!) on FIELD_DEFINITION
	directive @inaccessible on FIELD_DEFINITION | OBJECT | INTERFACE | SCALAR | ENUM | ARGUMENT_DEFINITION | INPUT_FIELD_DEFINITION
	directive @tag(name: String!) repeatable on FIELD_DEFINITION | OBJECT | INTERFACE | SCALAR | ENUM | ARGUMENT_DEFINITION | INPUT_FIELD_DEFINITION
	';

			return $sdl . $federation_directives;

		} catch ( \Exception $e ) {
			return '';
		}
	}
}
