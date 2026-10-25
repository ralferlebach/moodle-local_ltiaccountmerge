moodle-[component_pluginname]
=============================

[![Moodle Plugin CI](https://github.com/moodle-an-hochschulen/moodle-[component_pluginname]/actions/workflows/moodle-plugin-ci.yml/badge.svg?branch=main)](https://github.com/moodle-an-hochschulen/moodle-[component_pluginname]/actions?query=workflow%3A%22Moodle+Plugin+CI%22+branch%3Amain)

[A short description of the plugin]


Requirements
------------

This plugin requires Moodle [Version]+


Motivation for this plugin
--------------------------

[The motivation to write this plugin]


Installation
------------

Install the plugin like any other plugin to folder
[path to plugin]

See http://docs.moodle.org/en/Installing_plugins for details on installing Moodle plugins


Usage & Settings
----------------

After installing the plugin, it is ready to use without the need for any configuration.

==OR==

After installing the plugin, it does not do anything to Moodle yet.

To configure the plugin and its behaviour, please visit:
Site administration -> [path to the plugin settings]

There, you find [x] settings:

[Describe the settings]

If you want to learn more about using [plugin type] plugins in Moodle, please see https://docs.moodle.org/en/[plugintype].


Capabilities
------------

This plugin also introduces these additional capabilities:

* **[component]/[pluginname]::[capability]** - [Short description of the capability and its default assignation]
* **[component]/[pluginname]::[capability]** - [Short description of the capability and its default assignation]

==OR==

This plugin does not add any additional capabilities.


Scheduled Tasks
---------------

This plugin also introduces these additional scheduled tasks:

* **\[pluginname]\task\[taskname]** - [Short description of the scheduled task]\ By default, the task is disabled.

==AND/OR==

This plugin also introduces these additional scheduled tasks:

* **\[pluginname]\task\[taskname]** - [Short description of the scheduled task]\ By default, the task is enabled and runs [the standard schedule].

==OR==

This plugin does not add any additional scheduled tasks.


How this plugin works [ / Pitfalls]
-----------------------------------

[How it works]


Theme support
-------------

This plugin is developed and tested on Moodle Core's Boost theme.
It should also work with Boost child themes, including Moodle Core's Classic theme. However, we can't support any other theme than Boost.

==OR==

This plugin acts behind the scenes, therefore it should work with all Moodle themes.
This plugin is developed and tested on Moodle Core's Boost theme.
It should also work with Boost child themes, including Moodle Core's Classic theme. However, we can't support any other theme than Boost.


Plugin repositories
-------------------

This plugin is published and regularly updated in the Moodle plugins repository:
http://moodle.org/plugins/view/[component_pluginname]

==OR==

This plugin is not published in the Moodle plugins repository.

The latest development version can be found on Github:
https://github.com/moodle-an-hochschulen/moodle-[component_pluginname]


Bug and problem reports / Support requests
------------------------------------------

This plugin is carefully developed and thoroughly tested, but bugs and problems can always appear.

Please report bugs and problems on Github:
https://github.com/ralferlebach/moodle-[component_pluginname]/issues

We will do our best to solve your problems, but please note that due to limited resources we can't always provide per-case support.


Feature proposals
-----------------

Due to limited resources, the functionality of this plugin is primarily implemented for our own local needs and published as-is to the community. We are aware that members of the community will have other needs and would love to see them solved by this plugin.

Please issue feature proposals on Github:
https://github.com/ralferlebach/moodle-[component_pluginname]/issues

Please create pull requests on Github:
https://github.com/ralferlebach/moodle-[component_pluginname]/pulls

We are always interested to read about your feature proposals or even get a pull request from you, but please accept that we can handle your issues only as feature _proposals_ and not as feature _requests_.


Moodle release support
----------------------

Due to limited resources, this plugin is only maintained for the most recent major release of Moodle as well as the most recent LTS release of Moodle. Bugfixes are backported to the LTS release. However, new features and improvements are not necessarily backported to the LTS release.

Apart from these maintained releases, previous versions of this plugin which work in legacy major releases of Moodle are still available as-is without any further updates in the Moodle Plugins repository.

There may be several weeks after a new major release of Moodle has been published until we can do a compatibility check and fix problems if necessary. If you encounter problems with a new major release of Moodle - or can confirm that this plugin still works with a new major release - please let us know on Github.

This plugin is designed to be compatible with all currently supported versions of Moodle, leveraging its latest APIs. However, if you are using a legacy version of Moodle, we kindly advise against installing or using this plugin. Instead, we strongly recommend updating your Moodle instance to a supported version to ensure security and compliance with current technological standards. Thank you for your understanding.


Translating this plugin
-----------------------

This Moodle plugin is provided with English and German language packs only. Translations into other languages must be managed through AMOS (https://lang.moodle.org), where they will become part of Moodle's official language pack.

As the plugin creator, we continue to maintain the German translation. For all other languages, we kindly ask you to contribute your translations directly in AMOS. These contributions will be reviewed by Moodle's official language pack maintainers before being included in the official repository.

Thank you for supporting the global Moodle community!


Right-to-left support
---------------------

This plugin has not been tested with Moodle's support for right-to-left (RTL) languages.
If you want to use this plugin with a RTL language and it doesn't work as-is, you are free to send us a pull request on Github with modifications.


Maintainers
-----------

The plugin is maintained by\
Ralf Erlebach (or any other given author or institution)


Copyright
---------

The copyright of this plugin is held by\
Ralf Erlebach (or any other given author or institution)

Individual copyrights of individual developers are tracked in PHPDoc comments and Git commits.
