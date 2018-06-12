# GraphQL Nested Mutations

## Introduction
Adds support for nesting mutations by
1. Updating the scaffolded input types to allow nested data. 
2. Augmenting the scaffolded resolvers to handle this extra data and delegate to the appropriate resolvers for each type

## Requirements
* SilverStripe CMS ^4.0

## Usage
Define exactly which Scaffolded DataObjects allow nested mutations of their `has_one` and `has_many` relationships

```
SilverStripe\GraphQL\Controller:
  schema:
    scaffolding:
      allowNestedMutations:
        - <Namespace/DataObjectClassName>
```

Don't forget to allow update and/or create mutation operations on the scaffolders also. e.g.

```
SilverStripe\GraphQL\Controller:
  schema:
    scaffolding:
      types:
        CustomDataObject:
          operations:
            create: true
            update: true
```

#### Notes:
Currently this will only work for Update and Create Mutations. Both these scaffolders extend the `MutationScaffolder`
 class which I hook into.
 