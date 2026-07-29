# Eloquent-FileMaker

[![Total Downloads](https://img.shields.io/packagist/dt/gearbox-solutions/eloquent-filemaker)](https://packagist.org/packages/gearbox-solutions/eloquent-filemaker)
[![Latest Stable Version](https://img.shields.io/packagist/v/gearbox-solutions/eloquent-filemaker)](https://packagist.org/packages/gearbox-solutions/eloquent-filemaker)
[![License](https://img.shields.io/packagist/l/gearbox-solutions/eloquent-filemaker)](https://github.com/gearbox-solutions/eloquent-filemaker/blob/2.x/LICENSE)

Eloquent-FileMaker is a PHP package for Laravel to make working with FileMaker databases through FileMaker's OData API easier. The goal of this project is to provide as similar an interface for working with FileMaker records through OData as you would get with working with MySQL in native Laravel.

This package lets you easily connect to your FileMaker database through its OData API and get record data as Laravel Models, with as many native Eloquent features supported as possible.

## Support

This package is built and maintained by [Gearbox Solutions](https://gearboxgo.com/). We build fantastic web apps with technologies like Laravel, Vue, React, and Node. If you would like assistance building your own web app, either using this package or other projects, please [contact us](https://gearboxgo.com/) for a free introductory consultation to discuss your project.

## Features

- Uses FileMaker's OData API for accessing your FileMaker data
- Support for accessing multiple files or with multiple sets of credentials
- FMModel class
  - Extends the base Model class, allowing compatibility with many standard model features
  - Relationship support
  - Container data read/write
  - Automatic name/table resolution
  - FileMaker -> Laravel field name remapping
- Eloquent query builder and base query builder
- Raw connection service for easy OData access
- FileMaker database connection driver
- And more!

## Supported Laravel Versions

We support the [currently supported versions of Laravel](https://laravel.com/docs/master/releases). Earlier versions of Laravel may be compatible, but could be dropped in the future if incompatible changes are required.

## What's new in 3.0

Version 3.0 replaces the FileMaker **Data API** connection with FileMaker's **OData API**. This is a major, breaking change to how the package talks to FileMaker under the hood, and it removes a few features that only made sense in the context of the Data API.

- Connections now talk to `https://host/fmi/odata/v4/{database}` instead of the Data API, using stateless HTTP Basic authentication on every request (no more session-token login/caching).
- Query filtering is compiled to OData `$filter` expressions instead of Data API "find requests" — this is a large internal simplification, but if you were relying on the exact shape of `getWheres()`/`toSql()`/`toRawSql()` output, that shape has changed.
- Entity sets are **actual FileMaker tables**, not layouts. `$layout`/`getLayout()`/`setLayout()`/`layout()` have all been removed — use the standard Eloquent `$table` property and `table()`/`getTable()` instead.
- Records are addressed by their real primary key rather than the Data API's internal `recordId`. `getRecordId()`, `getModId()`, `withModId()`, and `duplicate()` have all been removed.
- Container fields are written as base64-encoded values inline with your other field data, in the same request — no more separate container-upload request.
- FileMaker scripts (`script()`, `scriptPresort()`, `scriptPrerequest()`, `executeScript()`/`performScript()`) and portals (`portal()`, `limitPortal()`, `offsetPortal()`, `portalData()`) have no OData equivalent and have been removed. Related data should be accessed through Eloquent relationships instead, which already work as separate queries independent of portals.
- `setGlobalFields()` and `disconnect()` have been removed (no OData equivalent / no session to end).

### Upgrading from 2.x to 3.x

Run `composer require gearbox-solutions/eloquent-filemaker:^3.0` to upgrade to the latest version of the package.

You will need to:

1. Point your `database.php` connection config at a FileMaker server with OData enabled (see [Database configuration](#database-configuration) below), and remove the `version`/`cache_session_token` keys, which no longer apply.
2. Replace `protected $layout = '...';` with `protected $table = '...';` on your models (and `->layout('...')` calls with `->table('...')`).
3. Remove any usage of `recordId`, `modId`/`withModId`, `duplicate()`, scripts, portals, or `setGlobalFields()`/`disconnect()` — there is no OData equivalent.
4. Double check any code that inspects the raw shape of query builder responses — records are now flat associative arrays (e.g. `$record['name']`) instead of the Data API's `{fieldData, portalData, recordId, modId}` envelope.

<details>
<summary>What's new in 2.0 (historical)</summary>

- Added support for Laravel 11
- Empty fields in FileMaker are returned as null instead of an empty string by default
- FileMaker Sessions only last for the duration of a single request to Laravel instead of being reused for 15 minutes by default - this can be changed in the config
- Improvements to whereNot logic and implementation to make it behave more closely to what it should be

</details>

# Installation

Install `gearbox-solutions/eloquent-filemaker` in your project using Composer.

```
composer require gearbox-solutions/eloquent-filemaker
```

Your FileMaker Server (or FileMaker Cloud) must have OData access enabled for the database you want to connect to. See Claris's [OData API Guide](https://help.claris.com/en/odata-guide/) for how to enable and configure this on your server.

# Usage

With the package installed you can now have access to all the features of this package. There are a few different areas to configure.

## Database configuration

The first thing to do is to add a new data connection in your `database.php` config file. The connections you specify here will be used in your FMModel classes to configure which databases each model will connect to.

You may use the following code block below as a template, which has some good defaults.

```php
'filemaker' => [
    'driver' => 'filemaker',
    'host' => env('DB_HOST', 'fms.mycompany.com'),
    'database' => env('DB_DATABASE', 'MyFileName'),
    'username' => env('DB_USERNAME', 'myusername'),
    'password' => env('DB_PASSWORD', ''),
    'prefix' => env('DB_PREFIX', ''),
    'protocol' => env('DB_PROTOCOL', 'https'),
    'empty_strings_to_null' => env('DB_EMPTY_STRINGS_TO_NULL', true), // set to false to return empty strings instead of null values when fields are empty in FileMaker
    'request_timeout' => env('DB_REQUEST_TIMEOUT', 30), // set the request timeout in seconds (default 30)
]
```

You should add one database connection configuration for each FileMaker database you will be connecting to. Each file can have completely different configurations, and can even be on different servers.

Every request is authenticated individually using HTTP Basic authentication with the `username`/`password` you configure — there is no session/login step and nothing to cache between requests.

#### Prefix

The prefix configuration option adds a prefix to each of the table names which you specify. You don't need to specify a prefix, but it can be very convenient to do so, for example if you want to namespace a set of tables used by your web app.

## Model Classes

Creating model classes is the easiest way to access your FileMaker data, and is the most Laravel-like way of doing things. Create a new model class and change the extension class from `Model` to `FMModel`. This class change enables you to use the features of this package with your models.

### Artisan make:model command

You can use the default `php artisan make:model` command with a new `--filemaker` flag to make a new `FMModel` instead of the default `Model`. All options available to Laravel's native `make:model` command are still available for use.

```shell
php artisan make:model MyNewModel --filemaker
```

### Set FMModel as default with a model stub

If you would like all of your models to be published as FMModel by default so that you don't have to use `--filemaker` in your commands, you can use the following command to publish a model stub that will be used by `php artisan make:model` to set up new models.

```shell
php artisan vendor:publish --tag=eloquent-filemaker-override-model
```

This publish will create a `/stubs/model.stub` that will be used by `php artisan make:model` to set up new models. You should use this on projects that will only have models backed by FileMaker.

If you want to customize the model stub ONLY for when the `---filemaker` flag is used, you can do so with the following command:

```shell
php artisan vendor:publish --tag=eloquent-filemaker-stubs
```

or

```shell
php artisan vendor:publish --provider="GearboxSolutions\EloquentFileMaker\Providers\FileMakerConnectionServiceProvider"
```

This stub publish option is best if you are looking to have a mix of FileMaker backed models and another DB backed model.

### Things that work

The FMModel class extends the base Laravel Model class, and can be used very similarly. It supports many standard Eloquent query builder features for working with data, such as where(), find(), orderBy(), delete(), save(), and many more!

Model features like accessors and mutators are supported, as well as automatic table name resolution, event triggers, observers, belongsTo, hasOne, and hasMany relationships, serialization (with protected attributes, etc), and as many other things as we can make sure are compatible.

Our goal is to be able to use any of these Eloquent features which make sense, so this package will attempt to support as many as possible. Submit a pull request with an update or let us know if you think there's something not working which should be supported.

Be sure to read [Laravel's Eloquent Documentation](https://laravel.com/docs/master/eloquent) to see all the things the Eloquent Model class can do.

### Things that don't work

Because this class extends Model, all of the regular eloquent methods may show as available in your IDE, but some don't make sense in the context of FileMaker's OData API and therefore don't do anything, such as raw SQL queries.

### Setting the table name

Your queries against your FileMaker database require you to get data from a particular table. Eloquent-FileMaker supports Laravel's name guessing for tables, but in case your table names don't match you can specify a table name to use with your models by setting the standard Eloquent `$table` property on your model class.

```php
protected $table = 'MyTable';
```

### Null values and empty strings

Null is an important, expected possible value for developers when working with databases. FileMaker as a platform, historically, has not had a strong concept of a null value — a field which has not had a value written to it may contain an empty string. In order to make this behavior more web-developer-friendly, Eloquent FileMaker automatically converts the value of `''` in a FileMaker field to `null` when reading data.

If you would like to have empty FileMaker fields returned as empty strings you can set the `empty_strings_to_null` config value to false in your connection configuration.

Eloquent FileMaker will always automatically convert `null` values to `''` when writing data back to your FileMaker database to prevent errors.

### Read-only fields

Many fields in your FileMaker database will be read-only, such as summaries and calculations, though you'll still want to get them when retrieving data from your database. FMModels will attempt to write all modified attributes back to your FileMaker database. If you write a read-only field, such as a calculation field, you will receive an error when attempting to write the field back to your FileMaker database.

You can list attributes which should never be written back to FileMaker by setting the `$readOnlyFields` property on your model.

```php
protected $readOnlyFields = [
    'creationTimestamp',
    'modificationTimestamp',
];
```

### Container Fields

This package supports both reading and writing container field data.

#### Writing to container fields

When setting a container field you should set the value to be an `Illuminate/HTTP/File` or `Illuminate/HTTP/UploadedFile` object. These attributes will be base64-encoded and written back to your container fields, inline with the rest of your model's changes, when the `save()` method is called on your model object.

```php
$file = new File(storage_path('app/public/gator.jpg'));
$newPet->photo = $file;
$newPet->save();
```

### Renaming and Mapping FileMaker Fields

Sometimes you might be working with a FileMaker database with inconvenient field names. These fields can be remapped to model attributes by setting the `$fieldMapping` attribute. This should be an array of strings, mapping FileMaker Field Name => New Attribute Name. You can then use these names as regular Eloquent attributes and they will work with the correct fields in FileMaker

```php
protected $fieldMapping = [
  'My Inconveniently Named Field' => 'a_much_better_name'
];
```

and then you can get/set the attributes via....

```php
$myModel->a_much_better_name = 'my new value';
```

### Casting FileMaker Timestamp and Date fields

This package has special handling for casting FileMaker Timestamp and Date fields to Carbon instances for you. To take advantage of this, you must map the fields as you would with a native Laravel Model class. You can use the `$casts` property as you normally would for these attributes.

```php
protected $casts = [
    'nextAppointment' => 'datetime',
    'birthday' => 'date',
];
```

The format Date and Timestamp fields written to FileMaker can be changed via the `$dateFormat` property of your model. This value must be compatible with the format FileMaker expects for Timestamp values and will be the format written back into your database. One important requirement is that this must be a full timestamp format, not just a date format.

Here are some example formats:

```php
protected $dateFormat = 'n/j/Y g:i:s A'; // 7/1/1920 4:01:01 PM
protected $dateFormat = 'n/j/Y G:i:s'; // 7/1/1920 16:01:01
```

## Example FMModel Class

```php
// Person.php

class Person extends FMModel
{

    protected $table = "person";

    protected $fieldMapping = [
        'first name' => 'nameFirst',
        'last name' => 'nameLast'
    ];

    protected $casts = [
        'birthday' => 'date',
    ];


    public function pets(){
        return $this->hasMany(Pet::class);
    }

}
```

# The Base Query Builder and the FM Facade

Similar to the native `DB` facade, you can use the `FM` facade to generate and execute queries without working through models. While the FMModel and Eloquent query builder it uses will return nicely organized FMModel collections, the base query builder will return the flat records as returned directly by FileMaker's OData API.

The FM facade provides access to the `FMBaseBuilder` class, which is also utilized by the Eloquent Builder used by `FMModel` objects. Methods of the `FMBaseBuilder` are also available to the FMModel Eloquent builder.

With this package in place the `DB` facade will still work for queries against your FileMaker database for basic record queries like `DB::table('pets')->where('name', 'Cosmo')->first()`, but the `FM` facade will allow you to access more FileMaker-specific functionality, such as `getTableMetadata()`, and should generally be used instead of `DB` for accessing your FileMaker data.

Like the FMModel class and Eloquent builder, the goal is to support the same set of features as the `DB` facade so check out the [Laravel Query Builder Documentation](https://laravel.com/docs/master/queries) to see what the basic query builder features are. `where`, `orWhere`, `whereIn`, `whereNotIn`, `whereNull`, `whereNotNull`, `whereBetween`, `whereNot`, nested where groups (via closures), `orderBy`, `limit`/`offset`, and `count` are all supported and are compiled into an OData `$filter`/`$orderby`/`$top`/`$skip`.

#### Request customization methods

```php
->setRetries($retries) // set the number of retries for a request (value of 2 will make 3 requests in total)
->setTimeout($timeout) // set the timeout for a request in seconds
```

#### Examples:

Perform a find for a person named Jaina

```php
$person = FM::table('person')->where('nameFirst', 'Jaina')->first();
```

Find the 10 most recent invoices for a customer

```php
$invoices = FM::table('invoice')->where('customer_id', $customer->id)->orderByDesc('date')->limit(10)->get();
```

Group where clauses in a nested where by passing a closure, exactly as you would with Laravel's native query builder.

```php
$people = FM::table('person')
    ->where('age', '>', 10)
    ->where(function ($query) {
        $query->where('name_first', 'Barbara')
            ->orWhere('name_last', 'Sanches');
    })->get();

// $filter=age gt 10 and (name_first eq 'Barbara' or name_last eq 'Sanches')
```

Negate a group of where clauses with `whereNot`.

```php
$pets = FM::table('pet')
    ->where('status', 'active')
    ->whereNot(function ($query) {
        $query->where('type', 'dog')
            ->orWhere('type', 'cat');
    })->get();

// $filter=status eq 'active' and not (type eq 'dog' or type eq 'cat')
```

Get a table's field metadata (parsed from FileMaker's OData `$metadata` document)

```php
$fieldNames = FM::getTableMetadata('MyTableName');
```

Create a record with an array of field data

```php
FM::table('MyTableName')->fieldData($data)->createRecord();
```

## Relating Native Laravel models to FMModels

It is possible to have relationships between native Laravel Model objects from your MySQL database and FMModels created from your FileMaker database. To do this, you will need to set up both connections in your `database.config` file and then make sure your models are pointing to the right connection by setting the `$connection` propety in your Model and FMModel classes.

```php
protected $connection = 'theConnectionName';
```

Once the connections are set correctly, relationships from FMModel objects to sql-based Model objects should resolve correctly automatically. Relationships from regular `Model` objects to `FMModel` objects (version > 2.3.0 ) will require adding a new trait to your model class to enable the relationship to be created. For versions earlier than 2.3.0 or for more control over the relationship you can add a relationship connection manually using the examples below.

you can create relationships, such as a belongsTo, by manually creating a new eloquent-filemaker belongsTo object or importing a new trait and setting the appropriate keys.

### Using trait to create a relationship (2.3.0+)

The `HasHybridRelationships` trait allows the model to automatically resolve relationships from a `Model` to an `FMModel`. Here is an example of using the trait to create a native Laravel User `Model` in a SQL database to belong to a FileMaker-based Company `FMModel` class.

```php
// User.php

use GearboxSolutions\EloquentFileMaker\Database\Eloquent\Concerns\HasHybridRelationships;

class User extends Model
{
    use HasHybridRelationships;

    public function company()
    {
        // The Company class is an FMModel and is stored in FileMaker
        // The correct relationship will be resolved automatically thanks to the HasHybridRelationships trait
        return $this->belongsTo(Company::class, 'company_id', 'id');
    }
}
```

With this relationship created we can now get an FMModel of the Company the User belongs to like a normal relationship in a single database.

```php
// set $company to a FMModel of the User's Company
$company = $user->company;
```

### Manually creating a relationship

Using the `HasHybridRelationships` trait is the easiest way to create relationships between native Laravel models and FMModels. However, if you are using an older version of Eloquent FileMaker or want to manually manage the relationships you can establish the relationship by using the Eloquent FileMaker version of the relationship type. Each valid relationship type will be available under the `\GearboxSolutions\EloquentFileMaker\Database\Eloquent\Relations\` namespace.

Here is an example of setting a native Laravel User Model to belong to a FileMaker-based Company FMModel class.

```php
// User.php

class User extends Model
{
    public function company()
    {
        // The Company class is an FMModel and is stored in FileMaker
        return new \GearboxSolutions\EloquentFileMaker\Database\Eloquent\Relations\BelongsTo(Company::query(), $this, 'company_id', 'id', '');
    }
}
```

## Testing

This package's automated test suite lives in the `tests/` directory of this repository. Run it with:

```shell
composer install
vendor/bin/phpunit
```

## License

Eloquent-FileMaker is open-sourced software licensed under the MIT license.
