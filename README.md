#Eloquent FileMaker
Eloquent-filemaker is a PHP package for Laravel to make working with FileMaker databases through the FileMaker Data API easier. The goal of this project is to provide as similar an interface for working with FileMaker records through the Data API as you would get with working with MySQL in native Laravel.

This package lets you easily connect to your FileMaker database through the Data API and get record data as Laravel Models, with as many native features supported as possible.

##Features
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

#Installation
Install bluefeathergroup\eloquent-filemaker in your project using Composer.

```composer require bluefeathergroup\eloquent-filemaker```
#Usage
With the package installed you can now have access to all the features of this package. There are a few different areas to configure.

##Database configuration
The first thing to do is to add a new data connection in your ```database.php``` config file. The connections you specify here will be used in your FMModel classes to configure which databases each model will connect to.

You may use the following code block below as a template.

        'my-filemaker-connection' => [
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



## Models
Creating models is the easiest way to access your FileMaker data, and is the most Laravel-like way of doing things. Create a new model (you can use artisan: ```artisan make:model myModelName```) and change the class from ```Model``` to ```FMModel```. This class change enables you to use the features of this package with your models.



# The FM Facade