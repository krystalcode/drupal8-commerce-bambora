CONTENTS OF THIS FILE
---------------------
* Introduction
* Requirements
* Installation
* Configuration
* How It Works
* Troubleshooting
* Maintainers

INTRODUCTION
------------
This project integrates Bambora online payments into
the Drupal Commerce payment and checkout systems.
https://dev.na.bambora.com/docs/guides/merchant_quickstart
* For a full description of the module, visit the project page:
  https://www.drupal.org/project/commerce_bambora
* To submit bug reports and feature suggestions, or to track changes:
  https://www.drupal.org/project/issues/commerce_bambora


REQUIREMENTS
------------
This module requires the following:
* Submodules of Drupal Commerce package (https://drupal.org/project/commerce)
  - Commerce Core
  - Commerce Payment (and its dependencies)
* Beanstream PHP Library (https://github.com/bambora-na/beanstream-php)
* Bambora Merchant account (https://www.bambora.com/en/us/online/products/merchant-account)


INSTALLATION
------------
* This module needs to be installed via Composer, which will download
the required libraries.
composer require "drupal/commerce_bambora"
https://www.drupal.org/docs/8/extending-drupal-8/installing-modules-composer-dependencies

CONFIGURATION
-------------
* Create a new Bambora payment gateway.
  Administration > Commerce > Configuration > Payment gateways > Add payment gateway
  Bambora-specific settings available:
  - Merchant ID
  - Payments API Key
  - Profiles API Key
  Use the API credentials provided by your Bambora merchant account. It is
  recommended to enter test credentials and then override these with live
  credentials in settings.php. This way, live credentials will not be stored in the db.


HOW IT WORKS
------------

* General considerations:
  - The store owner must have a Bambora merchant account.
    Sign up here:
    https://www.bambora.com/en/us/online/products/merchant-account
  - Customers should have a valid credit card.
    - Bambora provides several dummy credit card numbers for testing:
      https://dev.na.bambora.com/docs/references/payment_APIs/test_cards

* Checkout workflow:
  It follows the Drupal Commerce Credit Card workflow.
  The customer should enter his/her credit card data
  or select one of the credit cards saved with Bambora
  from a previous order.

* Payment Terminal
  The store owner can Void, Capture and Refund the Bambora payments.


TROUBLESHOOTING
---------------
* No troubleshooting pending for now.


MAINTAINERS
-----------
Current maintainers:
* Shabana Navas (shabana.navas) - https://www.drupal.org/u/shabananavas
* Dimitris Bozelos (krystalcode) - https://www.drupal.org/u/krystalcode

This project has been developed by:
* Acro Media Inc - Visit https://www.acromedia.com for more information.
