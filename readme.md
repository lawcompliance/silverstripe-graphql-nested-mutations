# GraphQL Nested Mutations

## Introduction
Adds support for nesting mutations by suppling 3 different plugins
1. HasOneNestedMutationPlugin 
2. HasManyNestedMutationPlugin
3. ManyManyNestedMutationPlugin

Each plugin can be added to the relevant create or update operations.

When the plugin is added, fields from the `has_one`, `has_many` or `many_many` relationships
are added to the relevant model input types and then resolver aftwares are defined to handle this extra data

Where possible e.g in the `has_one` and `has_many` afterwares, the nested data is delegated to the correct nested input type resolver
## Requirements
* SilverStripe CMS ^4.0

## Usage
Activate the plugins on the desired model operations e.g 

```
<Namespace>\<DataObject>:
  operations:
    create:
      plugins:
        mutateHasOneRelations: true
        mutateHasManyRelations: true
    update:
      plugins:
        mutateHasOneRelations: true
        mutateHasManyRelations: true

<Namespace>\<AnotherDataObject>:
  operations:
    create:
      plugins:
        mutateManyManyRelations: true
    update:
      plugins:
        mutateManyManyRelations: true
```
In this example we are enabling nested mutations for the `has_one` and `has_many` relationships
 on the create and update operations for the `<Namespace>\<DataObject` but only enabling nested mutations
 on for the `many_many` relationships for the `<Namespace>\<AnotherDataObject>`

## Example mutations
#`has_one`

If you do not supply an `id` for the address, then a new address will be created
```
mutation{
  updateMember(input: {
    id: 1
    address:{
      suburb:"My Suburb"
    }
  }){
    id
    address{
      id
      suburb
    }
  }
}
```
In this example, an id is supplied, so the address will be updated
```
mutation{
  updateMember(input: {
    id: 1
    address:{
      id: 2
      suburb:"My Suburb"
    }
  }){
    id
    address{
      id
      suburb
    }
  }
}
```

#`has_many`

In the following query, the first token without an id is created, and the 2nd token with an id is updated.
If another other tokens existed in that `has_many` relation, they will be deleted
```
mutation{
  updateMember(input: {
    id: 1
    Tokens:[
      {
        token:"test"
      },{
        id: 12
		token: "updatedtoken"
      }
    ]
  }){
    id
    tokens{
      id
      token
    }
  }
}
```

#`has_many` with descendants

This gets tricky. Take the following example. An `Order` dataobject `has_many` OrderItems. However the `OrderItem` dataobject has many descendant objects such as 
`AdvancedOrderItem` and `ShippingOrderItem`. Each descendant includes extra fields that are only relavant to that descendant. So how do we send an array of nested
OrderItems, which possibly include the base OrderItem and it's dscendants.

This module tackles the problem by requiring the input data be split into seperate arrays for each dataobject type. In our example it might look like the following:

```
mutation{
  updateOrder(input: {
    id: 8
    orderItems:{
      AdvancedOrderItem: [
        {
          id: 9                         #existing
          title: "Oversized package"
          weight: 300
        }, {
          title: "Another oversized package"
          weight: 700
        }
      ]
      ShippingOrderItem:[
        {
          title:"Express"
          company:"FedEx"
        }
      ]
    }
  }){
    id
    otherOrderItems{
      id
      title
      _extend{
        advancedOrderItem{
          weight
        }
        shippingOrderItem{
          company
        }
      }
    }
  }
}
```

#`many_many`
In the following example we are associating products 1 and 2 with category 1. If any `many_many_extraField` exist
on these relationships, they can also be updated in this nested input aka `Quantity` in product 1
```
mutation{
  updateCategory(input: {
    id: 1
    title: "Test Category"
    products: [
      {
        id: 1
        quantity: "3"
      },{
        id: 2
      }
    ]
  }){
    id
    title
    products{
      id
      Title
    }
  }
}
```

 
