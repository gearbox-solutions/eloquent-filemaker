# Eloquent FileMaker
Eloquent-filemaker is a PHP package for Laravel to make working with FileMaker databases through the FileMaker Data API easier. The goal of this project is to provide as similar an interface for working with FileMaker records through the Data API as you would get with working with MySQL in native Laravel.

This package lets you easily connect to your FileMaker database through the Data API and get record data as Laravel Models, with as many native features supported as possible.

## Features
* Uses the FileMaker Data API for accessing your FileMaker data
* Support for accessing multiple files or with multiple sets of credentials
* FMModel class
    * Extends the base Model class, allowing compatibility with many standard model features
    * Relationship support
    * Container data handling
    * Automatic name/layout/table resolution
    * Portal data support
    * FileMaker -> Laravel field name remapping
* Automatic authentication and session management
* Eloquent query builder and base query builder
* Raw connection service for easy Data API access
* FileMaker database connection driver
* Running scripts
* And more!

## Requirements
Laravel 6.0 or later.

# Installation
Install `bluefeathergroup\eloquent-filemaker` in your project using Composer.

```
composer require bluefeathergroup\eloquent-filemaker
```
# Usage
With the package installed you can now have access to all the features of this package. There are a few different areas to configure.

## Database configuration
The first thing to do is to add a new data connection in your ```database.php``` config file. The connections you specify here will be used in your FMModel classes to configure which databases each model will connect to.

You may use the following code block below as a template.

        my-filemaker-connection' => [
            'driver' => 'filemaker',
            'host' => env('DB_HOST', 'fms.mycompany.com'),
            'database' => env('DB_DATABASE', 'MyFileName'),
            'username' => env('DB_USERNAME', 'myusername'),
            'password' => env('DB_PASSWORD', ''),
            'prefix' => env('DB_PREFIX', ''),
            'version' => env('DB_VERSION', 'vLatest'),
            'protocol' => env('DB_PROTOCOL', 'https'),
        ]

You should add one database connection configuration for each FileMaker database you will be connecting to. Each file can have completely different configurations, and can even be on different servers.

Sessions will be maintained on a per-connection basis and tokens will automatically be cached using whatever cache configuration you have set up for your Laravel app.

#### Prefix
The prefix configuration option adds a prefix to each of the layout/table names which you specify. You don't need to specify a prefix, but it can be very convenient to do so.

It is good practice to create layouts specifically for the Data API to use, rather than using your regular GUI or developer layouts, which may be slow and have unnecessary fields on them. Creating layouts specifically for your web applications allows for you to optimize your Data API usage and maximize the performance of your web application. With this in mind, an easy way to manage these layout is to organize them together in a folder and give them all a prefix so that you can know what they are used for. 

As an example, let's say you have three tables - Organizations, Contacts, and Invoices. You may way to create layouts for your web application, such as "dapi-organizations", "dapi-contacts" and "dapi-invoices". If you prefix them all with the same text you can set the prefix value so that you can refer to them as just "organizations", "contacts" and "invoices" in Laravel. If you name your model classes correctly following Laravel's naming guidelines you'll even be able to have the layouts automatically resolve for you and you won't have to enter them manually!



## Model Classes
Creating model classes is the easiest way to access your FileMaker data, and is the most Laravel-like way of doing things. Create a new model class and change the extension class from `Model` to `FMModel`. This class change enables you to use the features of this package with your models.

#### Things that work

The FMModel class extends the base Laravel Model class, and can be used very similarly. It supports many standard Eloquent query builder features for working with data, such as where(), find(), id(), orderBy(), delete(), save(), and many more! 

Model features like accessors and mutators are supported, as well as automatic table/layout name resolution, event triggers, observers, belogsTo, hasOne, and hasMany relationships, serialization (with protected attributes, etc), and as many other things as we can make sure are compatible.

Our goal is to be able to use any of these Eloquent features which make sense, so this package will attempt to support as many as possible. Submit a pull request with an update or let us know if you think there's something not working which should be supported.

Be sure to read [Laravel's Eloquent Documentation](https://laravel.com/docs/8.x/eloquent) to see all the things the Eloquent Model class can do.

#### Things that don't work
Because this class extends Model, all of the regular eloquent methods may show as available in your IDE, but some don't make sense in the context of FileMaker's Data API and therefore don't do anything. Some examples of this would be mass updates or raw SQL queries.

### Setting a layout
Your queries against your FileMaker database require you to get data from a particular layout. Eloquent-FileMaker supports Laravel's name guessing for tables, but in case your layout names don't match you can specify a layout name to use with your models by setting the `$layout` property on your model class.

```
protected $layout = 'MyLayout';
```

### Read-only fields
Many fields in your FileMaker database will be read-only, though you'll still want to get them when retrieving data from your database. FMModels will attempt to write all modified attributes back to your FileMaker database unless you specify that they should not be attempted. You can specify fields to NOT write back to FileMaker using the `$readOnlyFields` attribute on your model.

Here is an example of setting this attribute to fields you may not want to write:

```
    protected $readOnlyFields = [
        'id',
        'creationTimestamp',
        'creationAccount',
        'modificationTimestamp',
        'modificationAccount',
    ];
```

### Container Fields
This package supports both reading and writing container field data. Container fields are retrieved from FileMaker as attributes on your model which will contain a URL which can be used to retrieve the file from the container.

When setting a container field you should set it as an `Illuminate/HTTP/File` object. These attributes will be written back to your container fields along with any other model updates when the `save()` method is called on your model object.

It is important for the class to know which fields are container fields so that they can be handled appropriately. Any container fields should be listed in a `$containerFields` property of your model.

```
    protected $containerFields = [
        'photo'
    ];
```


# The Base Query Builder and the FM Facade

Similar to the native `DB` facade, you can use the `FM` facade to generate and execute queries without working through models. This is particularly useful to use the Data API features which are not table-based, such as setting globals or performing scripts, though you can also use it to retrieve records. While the FMModel and Eloquent query builder it uses will return nicely organized FMModel collections, the base query builder will return the direct responses from the FileMaker Data API.

The FM facade provides access to the `FMBaseQueryBuilder` class, which is also utilized by the Eloquent Builder used by `FMModel` objects. Methods of the `FMBaseQueryBuilder` are also available to the FMModel Eloquent builder.

With this package in place the `DB` facade will still work for queries against your FileMaker database for basic record queries like `DB::table('pets')->where('name', 'Cosmo')->first()`, but the `FM` facade will allow you to access more FileMaker-specific functionality, and should generally be used instead of `DB` for accessing your FileMaker data.

Like the FMModel class and Eloquent builder, the goal is to support the same set of features as the `DB` facade so check out the [Laravel Query Builder Docuemntation](https://laravel.com/docs/8.x/queries) to see what the basic query builder features are.

### FileMaker-specific Features in the FilMaker Query Builder and FM Facade

In addition to the basic query builder features, the `FMBaseQueryBuilder` class, accessed through the `FM` facade or the FMModel eloquent builder has many new FileMaker-specific methods which are available.

The FileMaker data API supports a number of parameters on requests for doing things like running scripts and passing parameters at different times in the query process. Check the [FileMaker Data API Guide](https://help.claris.com/en/data-api-guide) and more specifically, the Data API Reference Documentation to see which options each specific call supports. 

The Data API Reference Documentation can be viewed on a running FileMaker server by following the instructions in the [API Guide](https://help.claris.com/en/data-api-guide/#reference-information) 

In general:

"To view the reference on a FileMaker Server remote machine, open a browser and enter the URL
`https://host/fmi/data/apidoc/`
where `host` is the IP address or host name of the master machine running FileMaker Server."

Here are a list of methods which will allow you to set the  parameters for the Data API features. Note that most of these can be chain-called, like with the standard query builder.

#### Chainable
```
limit
offset
script
scriptParam
scriptPresort
scriptPresortParam
scriptPrerequest
scriptPrerequestParam
layoutResponse
portal
sort (alias for the native orderBy)
omit
fieldData
portalData
layout (alias for table)
```

#### Final-chain-link methods
```
findByRecordId
performScript
setContainer
duplicate
```