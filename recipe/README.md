# Symfony Flex recipe

This directory holds the [Symfony Flex](https://symfony.com/doc/current/setup/flex.html)
recipe for `thebenbenj/ticket-pilot-bundle`. When the recipe is available, installing the
package automatically:

- registers `TicketPilotBundle` in `config/bundles.php`;
- copies `config/packages/ticket_pilot.yaml` (a commented starter);
- adds the `TICKET_PILOT_*` variables to `.env`.

## Layout

```
recipe/
├── manifest.json
└── config/
    └── packages/
        └── ticket_pilot.yaml
```

## Publishing

Recipes live in a central repository, not in the package. To make this one auto-apply on
`composer require`, submit it to
[`symfony/recipes-contrib`](https://github.com/symfony/recipes-contrib) under
`thebenbenj/ticket-pilot-bundle/<version>/` (copy `manifest.json` and `config/` there),
following the [recipe guidelines](https://github.com/symfony/recipes/blob/main/README.rst).

Until it is merged, configure the bundle manually as shown in the project
[README](../README.md#configuration) — everything works the same, the recipe only removes
the manual setup step.
