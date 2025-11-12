# DrupalCamp Rome 2025 - AI Shop Assistant

This is the code repository for the **AI Shop Assistant** project presented at
DrupalCamp Rome 2025. This project leverages Drupal and **Centarro Commerce Kickstart** to create an AI-powered shopping assistant.

## Installation

First you need to [install DDEV](https://ddev.readthedocs.io/en/stable/).

After that you can clone the repository for the project:

```
git clone git@github.com:robertoperuzzo/commerce-kickstart-project.git shop-assistant
cd shop-assistant
```

Then you can start the project using the following DDEV commands:
```
ddev start
ddev composer install
ddev launch
```

Now follow the instructions to install Drupal and Commerce Kickstart with the
demo content checking the tickbox "Install all features with sample content".

Finally restore the DDEV snapshot:
```
ddev snapshot restore <snapshot_name>
```
