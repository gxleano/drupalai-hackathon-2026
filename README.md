# Drupal AI Hackathon 2026

This project is based on **Drupal CMS**, a fast-moving open source product that enables site builders to create Drupal
sites with smart defaults using only their browser.

This repository serves as the official starting point for hackathon participants.

## Participation workflow

Participants must **fork this repository** into a GitHub account of their choosing.

All development must happen in the forked repository.

When ready for review, participants must open a **pull request back to this repository** so that judges can review and
evaluate the submission.

## Getting started

If you want to use DDEV to run Drupal CMS locally, follow these steps:

1. Install DDEV following the documentation at [https://ddev.com/get-started/](https://ddev.com/get-started/)
2. Open a terminal and change to the root directory of this project
3. Run the following commands:

```shell
ddev start
ddev install
ddev launch
```

Drupal CMS has the same system requirements as Drupal core. You may use any supported local setup if you prefer.

See the Drupal User Guide for more information on installation options:
[https://www.drupal.org/docs/user_guide/en/installation-chapter.html](https://www.drupal.org/docs/user_guide/en/installation-chapter.html)

## AI provider and model requirements

This distribution ships with the **Mistral** provider preconfigured. Participants are required to use a **Mistral model**
for all AI-powered features. Several Mistral models are already preselected in the AI configuration, participants are
free to change the selected model to any other Mistral model they consider appropriate. Non-Mistral models are not permitted.

Participants may add any Drupal modules they require for their solution.

## Mistral API key configuration

You must configure your Mistral API key before using AI features.

Run the following command:

```shell
cp .ddev/.env.example .ddev/.env
```

Open the `.env` file and set the following variable:

```shell
MISTRAL_API_KEY=your_api_key_here
```

Then restart DDEV to load the environment variable:

```shell
ddev restart
```

## Configuration export requirement

Before opening a pull request, participants must export configuration using the following command:

```shell
ddev drush cex
```

The exported configuration must be committed and included in the pull request. Pull requests without exported configuration
will not be considered complete.
