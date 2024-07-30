# Eloquent-FileMaker
[![Total Downloads](https://img.shields.io/packagist/dt/gearbox-solutions/eloquent-filemaker)](https://packagist.org/packages/gearbox-solutions/eloquent-filemaker)
[![Latest Stable Version](https://img.shields.io/packagist/v/gearbox-solutions/eloquent-filemaker)](https://packagist.org/packages/gearbox-solutions/eloquent-filemaker)
[![License](https://img.shields.io/packagist/l/gearbox-solutions/eloquent-filemaker)](https://github.com/gearbox-solutions/eloquent-filemaker/blob/2.x/LICENSE)


Eloquent-FileMaker is a PHP package for Laravel to make working with FileMaker databases through the FileMaker Data API easier. The goal of this project is to provide as similar an interface for working with FileMaker records through the Data API as you would get with working with MySQL in native Laravel.

This package lets you easily connect to your FileMaker database through the Data API and get record data as Laravel Models, with as many native features supported as possible.

## Support

This package is built and maintained by [Gearbox Solutions](https://gearboxgo.com/). We build fantastic web apps with technologies like Laravel, Vue, React, and Node. If you would like assistance building your own web app, either using this package or other projects, please [contact us](https://gearboxgo.com/) for a free introductory consultation to discuss your project.

## Features
* Uses the FileMaker Data API for accessing your FileMaker data
* Support for accessing multiple files or with multiple sets of credentials
* FMModel class
    * Extends the base Model class, allowing compatibility with many standard model features
    * Relationship support
    * Container data read/write
    * Automatic name/layout/table resolution
    * Portal data read/write
    * FileMaker -> Laravel field name remapping
* Automatic authentication and session management
* Eloquent query builder and base query builder
* Raw connection service for easy Data API access
* FileMaker database connection driver
* Running scripts
* And more!

## Supported Laravel Versions
We support the [currently supported versions of Laravel](https://laravel.com/docs/master/releases). Earlier versions of Laravel may be compatible, but could be dropped in the future if incompatible changes are required.

## What's new in 2.0
* Added support for Laravel 11
* Empty fields in FileMaker are returned as null instead of an empty string by default
* FileMaker Sessions only last for the duration of a single request to Laravel instead of being reused for 15 minutes by default - this can be changed in the config 
* Improvements to whereNot logic and implementation to make it behave more closely to what it should be

### Upgrading from 1.x to 2.x
Run `composer require gearbox-solutions/eloquent-filemaker:^2.0` to upgrade to the latest version of the package.

#### Potential changes to your code

The usage of the package has generally remained the same. However, there are a few changes which may affect your code. Read the changes below to see what refactoring may be necessary when upgrading.

##### Major - Changes to empty fields
In version 1.0, empty fields in FileMaker were returned as an empty string. In version 2.0, empty fields are returned as null. This change makes working with FileMaker data a bit more like what a Laravel developer would expect from a database.

If you'd like to continue with the old behavior your can change the `empty_strings_to_null` config value to false to keep with the empty strings. Otherwise, if you have any code which relies on empty fields being returned as an empty string, you may need to refactor your code to work with the new behavior.

##### Minor - Improvements to whereNot logic
There were some cases where whereNot may return results that were probably not correct or expected. This has been fixed in version 2.0. If you have any code which relies on the old, incorrect behavior of whereNot, you may need to refactor your code to work with the new corrected behavior.

# Installation
Install `gearbox-solutions/eloquent-filemaker` in your project using Composer.

```
composer require gearbox-solutions/eloquent-filemaker
```
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
    'version' => env('DB_VERSION', 'vLatest'),
    'protocol' => env('DB_PROTOCOL', 'https'),
    'cache_session_token' => env('DB_CACHE_SESSION_TOKEN', true), // set to false to log out after each reqeust. This can be slower than re-using a session token, but allows for globals to be set for individual user values.
    'empty_strings_to_null' => env('DB_EMPTY_STRINGS_TO_NULL', true), // set to false to return empty strings instead of null values when fields are empty in FileMaker
]
```
You should add one database connection configuration for each FileMaker database you will be connecting to. Each file can have completely different configurations, and can even be on different servers.

If `cache_session_token` is true, login sessions will be maintained on a per-connection basis and tokens will automatically be cached using whatever cache configuration you have set up for your Laravel app. This prevents the need to re-login to the Data API for each request, which can be a significant performance improvement. If you have a cache configured for your Laravel app, you should generally this to true.

#### Prefix
The prefix configuration option adds a prefix to each of the layout/table names which you specify. You don't need to specify a prefix, but it can be very convenient to do so.

It is good practice to create layouts specifically for the Data API to use, rather than using your regular GUI or developer layouts, which may be slow and have unnecessary fields on them. Creating layouts specifically for your web applications allows for you to optimize your Data API usage and maximize the performance of your web application. With this in mind, an easy way to manage these layout is to organize them together in a folder and give them all a prefix so that you can know what they are used for.

As an example, let's say you have three tables - Organizations, Contacts, and Invoices. You may way to create layouts for your web application, such as "dapi-organizations", "dapi-contacts" and "dapi-invoices". If you prefix them all with the same text you can set the prefix value so that you can refer to them as just "organizations", "contacts" and "invoices" in Laravel. If you name your model classes correctly following Laravel's naming guidelines you'll even be able to have the layouts automatically resolve for you and you won't have to enter them manually!

## Laravel Cache Configuration

Eloquent FileMaker will use your app's cache to cache FileMaker Data API session tokens between requests. For this to work, you need to make sure that you are not using your FileMaker connection as your cache driver.

Laravel's default is to use the `database` cache driver. If your FileMaker database connection is also your default driver, you will need to change your cache configuration to something else, such as `file` or `redis`. You can change your cache driver in your `.env`

Laravel 11:
```
cache_store=file
```

Laravel 10 and earlier
```
cache_driver=file
```

## Model Classes
Creating model classes is the easiest way to access your FileMaker data, and is the most Laravel-like way of doing things. Create a new model class and change the extension class from `Model` to `FMModel`. This class change enables you to use the features of this package with your models.



#### Things that work

The FMModel class extends the base Laravel Model class, and can be used very similarly. It supports many standard Eloquent query builder features for working with data, such as where(), find(), id(), orderBy(), delete(), save(), and many more!

Model features like accessors and mutators are supported, as well as automatic table/layout name resolution, event triggers, observers, belongsTo, hasOne, and hasMany relationships, serialization (with protected attributes, etc), and as many other things as we can make sure are compatible.

Our goal is to be able to use any of these Eloquent features which make sense, so this package will attempt to support as many as possible. Submit a pull request with an update or let us know if you think there's something not working which should be supported.

Be sure to read [Laravel's Eloquent Documentation](https://laravel.com/docs/8.x/eloquent) to see all the things the Eloquent Model class can do.

#### Things that don't work
Because this class extends Model, all of the regular eloquent methods may show as available in your IDE, but some don't make sense in the context of FileMaker's Data API and therefore don't do anything. Some examples of this would be mass updates or raw SQL queries.

### Setting a layout
Your queries against your FileMaker database require you to get data from a particular layout. Eloquent-FileMaker supports Laravel's name guessing for tables, but in case your layout names don't match you can specify a layout name to use with your models by setting the `$layout` property on your model class.

```php
protected $layout = 'MyLayout';
```

### Null values and empty strings
Null is an important, expected possible value for developers when working with databases. FileMaker as a platform, unfortunately, does not have the concept of a null value. A field which has not had a value written to it instead contains an empty string. In order to make this behavior more web-developer-friendly, Eloquent FileMaker automatically converts the value of `''` in a FileMaker field to `null` when reading data from the Data API.

If you would like to have empty FileMaker fields returned as empty strings you can set the `empty_strings_to_null` config value to false in your connection configuration.

Eloquent FileMaker will always automatically convert `null` values to `''` when writing data back to your FileMaker database to prevent errors.

### Read-only fields
Many fields in your FileMaker database will be read-only, such as summaries and calculations, though you'll still want to get them when retrieving data from your database. FMModels will attempt to write all modified attributes back to your FileMaker database. If you write a read-only field, such as a calculation field, you will receive an error when attempting to write the field back to your FileMaker database.

### Container Fields
This package supports both reading and writing container field data. Container fields are retrieved from FileMaker as attributes on your model which will contain a URL which can be used to retrieve the file from the container.

Please note: The FileMaker Data API does not allow you to write to container fields in related records:

[FileMaker Data API Container Documentation](https://help.claris.com/en/data-api-guide/content/upload-container-data.html)
> The container field must be a field in the table occurrence of the specified layout. It cannot be a container field in a related table.

#### Writing to container fields
When setting a container field you should set the value to be an `Illuminate/HTTP/File` or `Illuminate/HTTP/UploadedFile` object. These attributes will be written back to your container fields along with any other model updates when the `save()` method is called on your model object.
```php
$file = new File(storage_path('app/public/gator.jpg'));
$newPet->photo = $file;
$newPet->save();
```

#### Custom filenames when inserting files into containers
By default, files are inserted into containers using the filename of the file you are inserting. If you wish to set a new filename when the file is inserted into the container you can do so by passing the file and filename together in an array when setting your container.
```php
$file = new File(storage_path('app/public/gator.jpg'));
$newPet->photo = [$file, 'fluffy.jpg'];
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

### Fields from related records
If you have included fields from related records through relationships on your Data API layouts you will need to add a `$fieldMapping` property to be able to access your related data.

For example, if you have a Person table with a one-to-one relationship to a record of the first car they owned:

```php
protected $fieldMapping = [
  'person_CARfirst::color' => 'first_car_color',
  'person_CARfirst::make' => 'first_car_make',
  'person_CARfirst::model' => 'first_car_model'
];
```

The related data can be get/set just like any other attribute of the model. The data will be read from and written back to the first related record.

```php
$personFirstCarColor = $person->first_car_color;
```


### Portal Data
Portal data can be accessed as an attribute based on the portal's object name on your FileMaker Layout. Fields can be accessed using array keys of the field name.

For example, if you have a portal on a layout whose object name is "person_pet_portal" based on the "person_PET" relationship you can access your portal data via an array of that attribute:

```php
// Get the name of the first related Pet
$firstPetName = $person->person_pet_portal[0]['person_PET::name'];
```

You can write back data to the portal the same way:
```php
// Set the 'type' of the second related pet in the portal
$person->person_pet_portal[1]['person_PET::type'] = 'cat';
```


### Casting FileMaker Timestamp and Date fields
This package has special handling for casting FileMaker Timestamp and Date fields to Carbon instances for you. To take advantage of this, you must map the fields as you would with a native Laravel Model class. You can use the `$casts` property as you normally would for these attributes.

```php
protected $casts = [
    'nextAppointment' => 'datetime',
    'birthday' => 'date',
];
```

The format Date and Timestamp fields written to FileMaker can be changed via the `$dateFormat` property of your model. This value must be compatible with the format output from the FileMaker Data API for Timestamp values and will be the format written back into your database. One important requirement is that this must be a full timestamp format, not just a date format.

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

    protected $layout = "person";

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

Similar to the native `DB` facade, you can use the `FM` facade to generate and execute queries without working through models. This is particularly useful to use the Data API features which are not table-based, such as setting globals or performing scripts, though you can also use it to retrieve records. While the FMModel and Eloquent query builder it uses will return nicely organized FMModel collections, the base query builder will return the direct responses from the FileMaker Data API.

The FM facade provides access to the `FMBaseQueryBuilder` class, which is also utilized by the Eloquent Builder used by `FMModel` objects. Methods of the `FMBaseQueryBuilder` are also available to the FMModel Eloquent builder.

With this package in place the `DB` facade will still work for queries against your FileMaker database for basic record queries like `DB::table('pets')->where('name', 'Cosmo')->first()`, but the `FM` facade will allow you to access more FileMaker-specific functionality, and should generally be used instead of `DB` for accessing your FileMaker data.

Like the FMModel class and Eloquent builder, the goal is to support the same set of features as the `DB` facade so check out the [Laravel Query Builder Documentation](https://laravel.com/docs/8.x/queries) to see what the basic query builder features are.

### FileMaker-specific Features in the FileMaker Query Builder and FM Facade

In addition to the basic query builder features, the `FMBaseQueryBuilder` class, accessed through the `FM` facade or the FMModel eloquent builder has many new FileMaker-specific methods which are available.

The FileMaker data API supports a number of parameters on requests for doing things like running scripts and passing parameters at different times in the query process. Check the [FileMaker Data API Guide](https://help.claris.com/en/data-api-guide) and more specifically, the Data API Reference Documentation to see which options each specific call supports.

The Data API Reference Documentation can be viewed on a running FileMaker server by following the instructions in the [API Guide](https://help.claris.com/en/data-api-guide/#reference-information)

In general:

"To view the reference on a FileMaker Server remote machine, open a browser and enter the URL
`https://host/fmi/data/apidoc/`
where `host` is the IP address or host name of the master machine running FileMaker Server."

Here are a list of methods which will allow you to set the  parameters for the Data API features. Note that most of these can be chain-called, like with the standard query builder.

#### Start with
```php
FM::table()
FM::connection()
FM::layout() // alias for table()
FM::setGlobalFields() // not chainable
```

#### Chainable
```php
// standard query-builder stuff like where, orderBy, etc.
->limit( $value )
->offset( $value )
->script( $scriptName, $param)
->scriptParam( $param )
->scriptPresort( $scriptName, $param
->scriptPresortParam( $param )
->scriptPrerequest( $scriptName, $param )
->scriptPrerequestParam( $param )
->layoutResponse( $layoutName )
->portal( $portalName )
->portalLimit( $portalName, $limit )
->portalOffset( $portalName, $startingRecord  )
->sort() // alias for the native orderBy()
->omit()
->fieldData( $array )
->portalData( $array )
```

#### Final-chain-link methods
```php
// standard query-builder stuff like get, first, etc.
->findByRecordId()
->performScript()
->setContainer()
->duplicate()
->createRecord()
->getLayoutMetadata()
```

#### Examples:
Perform a find for a person named Jaina
```php
$person = FM::table('person')->where('nameFirst', 'Jaina')->first();
```

Find the 10 most recent invoices for a customer
```php
$invoices = FM::layout('invoice')->where('customer_id', $customer->id)->orderByDesc('date')->limit(10)->get();
```

Get layout metadata, which includes field, portal, and value list information
```php
$layoutMetadata = FM::getLayoutMetadata('MyLayoutName');
```

Get layout metadata for a specific record
```php
$layoutMetadata = FM::layout('MyLayoutName')->recordId(879)->getLayoutMetadata();
```

Run a script
```php
$result = FM::layout('MyLayoutName')->performScript('MyScriptName');
```

Run a script with JSON data as a parameter
```php
$json = json_encode ([
    'name' => 'Joe Smith',
    'birthday' => '1/1/1970'
    'favorite_color' => 'blue'
]);

$result = FM::layout('globals')->performScript('New Contact Request'; $json);
```

Perform a script on a database other than the default database connection
```php
$result = FM::connection('MyOtherDatabaseConnectionName')->layout('MyLayoutName')->performScript('MyScriptName');
```

Create a record with an array of field data and then perform a script after record creation, within the same request
```php
FM::layout('MyLayoutName')->script('ScriptName')->fieldData($data)->createRecord();
```

Set a global field. The full `table_name::field_name` syntax is required for global fields.
```php
        $globalFields = [
            'GLOB::my_global_field' => 'New global value',
            'GLOB::other_global_field' => 'New global value',

        ];
        FM::setGlobalFields($globalFields);
```

## Logging out, disconnecting, and ending your Data API session

Eloquent-FileMaker attempts to automatically re-use session tokens by caching the session token between requests. This means that you will see the session remain open in your FileMaker Server admin console. It will be automatically disconnected by your server based on your server or database's disconnection settings.

If you would like to manually log out and end your session you can do so either through the FM facade or through a model.

```php
FM::connection()->disconnect();
```
 or
```php
MyModel::getConnectionResolver()->connection()->disconnect();
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

## License
Eloquent-FileMaker is open-sourced software licensed under the MIT license.
