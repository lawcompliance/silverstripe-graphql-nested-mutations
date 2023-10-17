<?php

namespace Internetrix\GraphQLNestedMutations\Plugins;

use GraphQL\Type\Definition\ResolveInfo;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\GraphQL\Schema\DataObject\FieldAccessor;
use SilverStripe\GraphQL\Schema\Exception\SchemaBuilderException;
use SilverStripe\GraphQL\Schema\Field\ModelMutation;
use SilverStripe\GraphQL\Schema\Interfaces\ModelMutationPlugin;
use SilverStripe\GraphQL\Schema\Schema;
use SilverStripe\GraphQL\Schema\Type\InputType;
use SilverStripe\GraphQL\Schema\Type\Type;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataObjectSchema;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\ManyManyList;

class ManyManyNestedMutationPlugin implements ModelMutationPlugin
{
    const IDENTIFIER = 'mutateManyManyRelations';

    /**
     * @return string
     */
    public function getIdentifier(): string
    {
        return self::IDENTIFIER;
    }

    /**
     * @return array
     */
    protected function getResolver(array $config): array
    {
        return [static::class, 'mutateManyManyRelations'];
    }

    /**
     * @param ModelMutation $mutation
     * @param Schema $schema
     * @param array $config
     * @throws SchemaBuilderException || Exception
     */
    public function apply(ModelMutation $mutation, Schema $schema, array $config = []): void
    {
        $dataClass = $mutation->getModel()->getSourceClass();
        $instance =  Injector::inst()->get($dataClass);

        $prefix = ucfirst($mutation->getName());
        $mutationType = (stripos($prefix, 'Update') === 0) ? 'Update' : 'Create';

        $parentInputTypeName = $prefix . 'Input';
        $parentInputType = $schema->getType($parentInputTypeName);

        Schema::invariant(
            $parentInputType,
            'Could not find the input type %s.',
            $parentInputTypeName
        );

        $this->addInputTypesToSchema($instance, $schema, $parentInputType, $mutationType);

        $context = [
            'typeNameMap' => []
        ];

        $mutation->addResolverAfterware(
            $this->getResolver($config),
            $context
        );
    }

    /**
     * @param DataObject $instance
     * @param Schema $schema
     * @param Type $parentInputType
     * @param string $mutationType
     * @throws Exception
     */
    private function addInputTypesToSchema(DataObject $instance, Schema $schema, $parentInputType, $mutationType){

        $manyMany = $instance->ManyMany();
        foreach($manyMany as $relationship => $class){
            if (is_array($class) && isset($class['through'])){
                $class = $class['through'];
            }elseif(is_array($class)){
                throw new Exception('Class is an array but the "through" class is not defined');
            }

            $typeName = $mutationType . $schema->getConfig()->getTypeNameForClass($class) . 'ManyManyInput';
            $manyManyModelType = $schema->getType($typeName);

            //If the type does not exist, then we need to create it
            if(!$manyManyModelType){

                $manyManyModelType = InputType::create($typeName);
                $schema->addType($manyManyModelType);

                $manyManyModelType->addField('id', 'Int');

                //however manymany lists can also contains extrafield...add them if they exist
                $databaseSchema = Injector::inst()->get(DataObjectSchema::class);
                $extraFields = $databaseSchema->manyManyExtraFieldsForComponent($instance->ClassName, $relationship);

                if($extraFields){
                    $relationshipInstance = Injector::inst()->get($class);
                    foreach ($extraFields as $dbFieldName => $dbFieldType) {
                        $result = Injector::inst()->create($dbFieldType, $dbFieldName);

                        if ($result instanceof DBField) {
                            $manyManyModelType->addField(
                                FieldAccessor::formatField($dbFieldName),
                                ['type' => $result->config()->get('graphql_type')]
                            );
                        }
                    }
                }
            }

            $parentInputType->addField(
                FieldAccessor::formatField($relationship),
                '[' . $manyManyModelType->getName() . ']'
            );
        }
    }

    /**
     * @param array $context
     * @return Closure
     */
    public static function mutateManyManyRelations(array $context)
    {
        return function ($obj, array $args, array $context, ResolveInfo $info) {
            $input = $args['input'];
            $manyManys = $obj->manyMany();
            if($manyManys){
                foreach($manyManys as $relationship => $class){
                    $relationshipInput = $input[FieldAccessor::formatField($relationship)] ?? null;
                    if($relationshipInput){
                        $items = $relationshipInput;

                        /* @var $manyManyList ManyManyList */
                        $manyManyList = $obj->$relationship();
                        $existingCount = $manyManyList->count();

                        $existingIDs = [];
                        foreach($items as $extraFields){
                            $itemID = $extraFields['id'];
                            unset($extraFields['id']);
                            $existingIDs[] = $itemID;
                            //TODO we should validate the itemID to ensure it exists

                            $formattedExtraFields = [];
                            foreach ($extraFields as $fieldName => $value) {
                                $formattedExtraFields[ucfirst($fieldName)] = $value; //TODO: use something more like FieldAccessor::normaliseField
                            }
                            $manyManyList->add($itemID, $formattedExtraFields);
                        }

                        $toDeleteList = clone $manyManyList;

                        if(!empty($existingIDs) && $existingCount){
                            $toDeleteList = $toDeleteList->exclude('ID', $existingIDs);
                        }

                        if($toDeleteList->count() && $existingCount){
                            foreach($toDeleteList as $toDeleteItem){
                                $manyManyList->removeByID($toDeleteItem->ID);
                            }
                        }
                    }
                }
            }
            return $obj;
        };
    }
}
