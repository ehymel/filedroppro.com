<?php

/**
 * Returns the importmap for this application.
 *
 * - "path" is a path inside the asset mapper system. Use the
 *     "debug:asset-map" command to see the full list of paths.
 *
 * - "entrypoint" (JavaScript only) set to true for any module that will
 *     be used as an "entrypoint" (and passed to the importmap() Twig function).
 *
 * The "importmap:require" command can be used to add new entries to this file.
 *
 * @return array<string, array{    // Import name as key, description of the imported file as value
 *     path: string,               // Logical, relative or absolute path to the file
 *     type?: 'js'|'css'|'json',   // Type of the file, defaults to 'js'
 *     entrypoint?: bool,          // Whether the file is an entrypoint, for 'js' only
 * }|array{
 *     version: string,            // Version of the remote package
 *     package_specifier?: string, // Remote "package-name/path" specifier, defaults to the import name
 *     type?: 'js'|'css'|'json',
 *     entrypoint?: bool,
 * }>
 */
return [
    'app' => ['path' => './assets/app.js', 'entrypoint' => true],
    '@symfony/stimulus-bundle' => ['path' => './vendor/symfony/stimulus-bundle/assets/dist/loader.js'],
    '@hotwired/stimulus' => ['version' => '3.2.2'],
    '@hotwired/turbo' => ['version' => '8.0.23'],
    '@popperjs/core' => ['version' => '2.11.8'],
    'bootstrap' => ['version' => '5.3.8'],
    'bootstrap/dist/css/bootstrap.min.css' => ['version' => '5.3.8', 'type' => 'css'],
    'bootstrap-icons/font/bootstrap-icons.css' => ['version' => '1.13.1', 'type' => 'css'],
    'bootstrap-datepicker' => ['version' => '1.10.1'],
    'bootstrap-datepicker/dist/css/bootstrap-datepicker3.standalone.css' => ['version' => '1.10.1', 'type' => 'css'],
    'sortable-tablesort' => ['version' => '4.1.7'],
    'sortable-tablesort/dist/sortable.min.css' => ['version' => '4.1.7', 'type' => 'css'],
    'sweetalert2' => ['version' => '11.26.25'],
    '@simplewebauthn/browser' => ['version' => '13.3.0'],
    '@web-auth/webauthn-stimulus' => ['version' => '5.3.5'],
    'jquery' => ['version' => '3.7.1'],
    'just-extend' => ['version' => '5.1.1'],
    'sortablejs' => ['version' => '1.15.7'],
    '@uppy/core' => ['version' => '5.2.0'],
    '@uppy/dashboard' => ['version' => '5.1.1'],
    '@uppy/aws-s3' => ['version' => '5.1.0'],
    '@uppy/utils' => ['version' => '7.2.0'],
    '@transloadit/prettier-bytes' => ['version' => '0.3.5'],
    'mime-match' => ['version' => '1.0.2'],
    'preact' => ['version' => '10.28.3'],
    '@uppy/store-default' => ['version' => '5.0.0'],
    'lodash/throttle.js' => ['version' => '4.17.21'],
    'namespace-emitter' => ['version' => '2.0.1'],
    'nanoid/non-secure' => ['version' => '5.1.6'],
    '@uppy/provider-views' => ['version' => '5.2.2'],
    '@uppy/thumbnail-generator' => ['version' => '5.1.0'],
    'preact/jsx-runtime' => ['version' => '10.28.3'],
    'classnames' => ['version' => '2.5.1'],
    'preact/hooks' => ['version' => '10.28.3'],
    'shallow-equal' => ['version' => '3.1.0'],
    'lodash/debounce.js' => ['version' => '4.17.23'],
    '@uppy/companion-client' => ['version' => '5.1.1'],
    '@uppy/core/dist/style.min.css' => ['version' => '5.2.0', 'type' => 'css'],
    '@uppy/dashboard/dist/style.min.css' => ['version' => '5.1.1', 'type' => 'css'],
    'wildcard' => ['version' => '1.1.2'],
    'p-queue' => ['version' => '8.1.1'],
    'exifr/dist/mini.esm.mjs' => ['version' => '7.1.3'],
    'p-retry' => ['version' => '6.2.1'],
    '@uppy/provider-views/dist/style.min.css' => ['version' => '5.2.2', 'type' => 'css'],
    'eventemitter3' => ['version' => '5.0.1'],
    'p-timeout' => ['version' => '6.1.4'],
    'retry' => ['version' => '0.13.1'],
    'is-network-error' => ['version' => '1.1.0'],
];
