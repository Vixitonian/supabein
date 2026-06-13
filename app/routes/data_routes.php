<?php

declare(strict_types=1);

function register_data_routes(\SupaBein\Router $router): void
{
    // All data routes use optional auth — policy engine decides access.

    $router->get(
        '/v1/data/:project_id/:table_name',
        [\SupaBein\Crud::class, 'handleList'],
        ['optional_auth_middleware']
    );

    $router->post(
        '/v1/data/:project_id/:table_name',
        [\SupaBein\Crud::class, 'handleInsert'],
        ['optional_auth_middleware']
    );

    $router->get(
        '/v1/data/:project_id/:table_name/:id',
        [\SupaBein\Crud::class, 'handleGet'],
        ['optional_auth_middleware']
    );

    $router->patch(
        '/v1/data/:project_id/:table_name/:id',
        [\SupaBein\Crud::class, 'handleUpdate'],
        ['optional_auth_middleware']
    );

    $router->delete(
        '/v1/data/:project_id/:table_name/:id',
        [\SupaBein\Crud::class, 'handleDelete'],
        ['optional_auth_middleware']
    );
}
