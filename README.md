# WPGraphQL Federations

[![Version](https://img.shields.io/badge/version-0.1.0-blue.svg)](https://github.com/Manuel-Antunes/blog-federado-gql-wordpress)
[![WPGraphQL](https://img.shields.io/badge/WPGraphQL-required-green.svg)](https://www.wpgraphql.com/)
[![Apollo Federation](https://img.shields.io/badge/Apollo%20Federation-v2-orange.svg)](https://www.apollographql.com/docs/federation/)

**WPGraphQL Federations** is a WordPress plugin that adds **Apollo Federation v2** support to [WPGraphQL](https://www.wpgraphql.com/). It allows your WordPress site to function as a subgraph in a federated GraphQL architecture, enabling seamless integration with other microservices.

## 🚀 The Problem

In modern microservices architectures, teams often use **GraphQL Federation** to combine multiple GraphQL services (subgraphs) into a single, unified gateway (supergraph). 

While WordPress is a powerful CMS and **WPGraphQL** provides an excellent API, it does not support Apollo Federation out of the box. Specifically:
- It lacks the required `_service` and `_entities` root fields.
- It doesn't support Federation directives like `@key`, `@shareable`, or `@external`.
- It cannot resolve entities based on their representation objects (e.g., resolving a Post by its ID from another service).

This makes it difficult to include WordPress in a larger federated ecosystem without complex custom code or brittle middleware.

## ✨ The Solution

**WPGraphQL Federations** bridges this gap by transforming WPGraphQL into a fully compliant Apollo Federation subgraph. It provides:

1.  **Automatic Entity Discovery:** Detects your Post Types, Taxonomies, Users, and Comments and makes them available as federated entities.
2.  **Runtime Registry:** A management interface to configure Federation v2 directives (`@key`, `@shareable`, etc.) for any GraphQL type or field.
3.  **Entity Resolution:** Automatically handles the `_entities` query, allowing other services to "join" data with WordPress objects using global IDs or custom keys.
4.  **Schema Augmentation:** Injects the necessary Federation scalars and types into the schema and provides the full SDL with directives via the `_service` field.

## 🛠 Features

- **Full Federation v2 Support:** Implements the latest Apollo Federation specification.
- **Admin Settings Page:** A user-friendly interface in the WordPress dashboard to manage federation settings.
- **Type-Level Directives:** Configure `@key`, `@shareable`, and `@inaccessible` for any Object Type.
- **Field-Level Directives:** Apply `@external`, `@requires`, `@provides`, `@override`, and `@tag` to specific fields.
- **Flexible Keys:** Support for both standard Relay Global IDs and internal database IDs as entity keys.
- **Custom Directives:** Support for adding additional custom directives to types.

## 📦 Installation

1.  Upload the `wp-graphql-federations` folder to the `/wp-content/plugins/` directory.
2.  Activate the plugin through the 'Plugins' menu in WordPress.
3.  Ensure [WPGraphQL](https://wordpress.org/plugins/wp-graphql/) is also installed and active.
4.  Run `composer install` in the plugin directory to ensure the autoloader is generated (if using the composer version).

## ⚙️ Configuration

Once activated, go to **Settings > WPGraphQL Federation** in your WordPress admin.

1.  **Enable Types:** Select the GraphQL types (Post Types, Users, etc.) you want to expose as entities.
2.  **Define Keys:** Specify the `@key` fields (usually `id`) that the gateway will use to identify these entities.
3.  **Field Directives:** Expand the "Manage Fields" section for any type to apply field-specific federation logic.
4.  **Save Changes:** The plugin will automatically update the schema and SDL.

## 🔍 Technical Implementation

### Entity Resolution
The plugin implements a robust `resolve_entity` method that understands how to fetch WordPress objects based on the `__typename` and key provided by the gateway. It supports:
- **Post Types:** Posts, Pages, and any custom post types.
- **Taxonomies:** Categories, Tags, and custom taxonomies.
- **Core Entities:** Users and Comments.

### SDL Generation
The plugin hooks into `graphql_schema_config` and `graphql_register_types` to inject:
- `scalar _Any` and `scalar _FieldSet`
- `type _Service { sdl: String }`
- `union _Entity` (containing all your enabled federated types)
- `_service` and `_entities` root queries.

## 📋 Requirements

- **PHP:** 7.4 or higher
- **WordPress:** 5.0 or higher
- **WPGraphQL:** 1.0 or higher

## 📄 License

This project is licensed under the MIT License.

---
Created by [Manuel Antunes](https://github.com/Manuel-Antunes)
