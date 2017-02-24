# Ticketit app

This is a Laravel 5.4 application with [Ticketit](https://github.com/thekordy/ticketit) pre-installed in it. This is meant to make installation of Ticketit as quick as possible and easier for those who are not familiar with Laravel.

Install this only if you'd like to install Ticketit as a standalone app. If you'd like to integrate Ticketit to your existing Laravel project, follow the maunual [installation guide](https://github.com/thekordy/ticketit#installation-manual) of the Ticketit repository.

## Installation

Open a terminal at the desired installation destination and run:

```
composer create-project --prefer-dist balping/ticketit-app ticketit
```

This pulls in all necessary libraries. Then cd into the installation directory and run the install script:

```
cd ticketit
php artisan ticketit:install
```

This asks some questions (database parameters, admin account login details).

The installation script will do pretty much everything for you to have Ticketit up and running. After installation is done, you might want to set up mail by editing the `.env` file and go through the settings in the admin panel.

## Notes

Please send Ticketit-related bugreports to the [Ticketit repo](https://github.com/thekordy/ticketit/issues). Only installer-related problems should be reported here.

If you move your installation folder to another path, you need to update the row with `slug='routes'` in table `ticketit_settings`.
