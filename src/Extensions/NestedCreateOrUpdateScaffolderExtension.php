<?php

namespace Internetrix\GraphQLNestedMutations;

use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\Type;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\GraphQL\Manager;
use SilverStripe\GraphQL\Scaffolding\Scaffolders\MutationScaffolder;
use SilverStripe\GraphQL\Scaffolding\Util\ScaffoldingUtil;
use SilverStripe\ORM\DataObjectSchema;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\GraphQL\Scaffolding\Interfaces\ResolverInterface;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;

class NestedCreateOrUpdateScaffolderExtension extends Extension
{

    public function onBeforeAddToManager(MutationScaffolder $scaffolder, Manager $manager){
        //only allow for Create & Update scaffolders
        $scaffolderClass = get_class($scaffolder) == 'SilverStripe\GraphQL\Scaffolding\Scaffolders\CRUD\Update' ? 'Update' : 'Create';
        $allowedTypes = [];
        $schema = Config::inst()->get('SilverStripe\GraphQL\Controller', 'schema');
        if(isset($schema['scaffolding']['allowNestedMutations'])){
            $allowedTypes = $schema['scaffolding']['allowNestedMutations'];
        }

        $class = $scaffolder->getDataObjectClass();
        if(in_array($class, $allowedTypes)){
            $inputTypeName = $scaffolder->typeName().$scaffolderClass.'InputType';     //$scaffolder->inputTypeName() is protected

            //SilverStripe\GraphQL\Scaffolding\Scaffolders\CRUD\Update.php adds this type before calling
            //this extension hook
            if($manager->hasType($inputTypeName)){
                $inputType = $manager->getType($inputTypeName);

                //the following closure is mostly copied from SilverStripe\GraphQL\Scaffolding\Scaffolders\CRUD\Update->generateInputType()
                $inputType->config['fields'] = function () use ($manager, $scaffolder, $class, $scaffolderClass) {
                    $fields = [];
                    $instance = $scaffolder->getDataObjectInstance();

                    // Setup default input args.. Placeholder!
                    $schema = Injector::inst()->get(DataObjectSchema::class);
                    $db = $schema->fieldSpecs($class);

                    unset($db['ID']);

                    foreach ($db as $dbFieldName => $dbFieldType) {
                        /** @var DBField $result */
                        $result = $instance->obj($dbFieldName);
                        // Skip complex fields, e.g. composite, as that would require scaffolding a new input type.
                        if (!$result->isInternalGraphQLType()) {
                            continue;
                        }
                        $arr = [
                            'type' => $result->getGraphQLType($manager),
                        ];
                        $fields[$dbFieldName] = $arr;
                    }

                    // TODO: maybe make it possible to set a whitelist or a blacklist of relations per object
                    $hasOnes = $instance->hasOne();

                    foreach($hasOnes as $relationship => $rclass){
                        $typeName = ScaffoldingUtil::typeNameForDataObject($rclass).'UpdateInputType';

                        if($manager->hasType($typeName)){
                            $fields[$relationship] = [
                                'type' => $manager->getType($typeName)
                            ];
                        }
                    }

                    // TODO: maybe make it possible to set a whitelist or a blacklist of relations per object
                    $hasManys = $instance->hasMany();

                    foreach($hasManys as $relationship => $class){
                        $typeName = $relationship.'Nested'.$scaffolderClass.'InputType';
                        if(!$manager->hasType($typeName)){
                            $fields[$relationship] = $this->buildNewType($typeName, $class,$manager);
                        }
                    }

                    return $fields;
                };
            }
        }
    }

    /*
     * Mostly complied from SilverStripe\GraphQL\Scaffolding\Scaffolders\CRUD\Update->generateInputType()
     * do not unset the ID
     */
    private function buildNewType($newTypeName, $instanceClassName, $manager){
        return [
            'type' => Type::listOf( (new InputObjectType([
                'name' => $newTypeName,
                'fields' => function () use ($manager, $instanceClassName) {
                    $fields = [];
                    $instance =  Injector::inst()->get($instanceClassName);

                    // Setup default input args.. Placeholder!
                    $schema = Injector::inst()->get(DataObjectSchema::class);
                    $db = $schema->fieldSpecs($instanceClassName);

                    foreach ($db as $dbFieldName => $dbFieldType) {
                        /** @var DBField $result */
                        $result = $instance->obj($dbFieldName);
                        // Skip complex fields, e.g. composite, as that would require scaffolding a new input type.
                        if (!$result->isInternalGraphQLType()) {
                            continue;
                        }
                        $arr = [
                            'type' => $result->getGraphQLType($manager),
                        ];
                        $fields[$dbFieldName] = $arr;
                    }
                    return $fields;
                }
            ])))
        ];
    }

    public function augmentMutation($obj, $args, $context, $info){
        $this->mutateHasOneRelations($obj, $args, $context);
        $this->mutateHasManyRelations($obj, $args, $context);

        // TODO: need to have logic here
        return true;
    }

    private function mutateHasOneRelations(DataObject $obj, $args, $context){

        $hasOnes = $obj->hasOne();
        if($hasOnes){

            foreach($hasOnes as $relationship => $class){
                if(isset($args['Input'][$relationship])){

                    $schemaConfig = Config::inst()->get('SilverStripe\GraphQL\Controller', 'schema');

                    /* @var $relObj DataObject */
                    $relObj = $obj->$relationship();
                    $resolverType = !($relObj && $relObj->exists()) ? 'Create' : 'Update';

                    //first workout if we are scaffolding the related class object
                    if(isset($schemaConfig['scaffolding']['types'][$class])){

                        //now check if we have explicitly set a custom resolver
                        if(isset($schemaConfig['scaffolding']['types'][$class]['operations'][strtolower($resolverType)]['resolver'])){
                            $resolverClassName = $schemaConfig['scaffolding']['types'][$class]['operations'][strtolower($resolverType)]['resolver'];

                            /* @var $resolver ResolverInterface */
                            $resolver = Injector::inst()->create($resolverClassName);
                        }else{
                            //otherwise we can use the default resolver
                            $resolver = Injector::inst()->createWithArgs(
                                "SilverStripe\GraphQL\Scaffolding\Scaffolders\CRUD\\" . $resolverType,
                                [$class]
                            );
                        }

                        $relationArgs = [
                            'ID' => $obj->{$relationship . 'ID'},
                            'Input' => $args['Input'][$relationship]
                        ];

                        $relObj = $resolver->resolve(null, $relationArgs, $context, null);
                        if($relObj && $relObj->exists()){
                            $obj->{$relationship . 'ID'} = $relObj->ID;
                        }
                    }
                }

            }
        }
    }

    private function mutateHasManyRelations(DataObject $obj, $args, $context){

        $hasMany = $obj->hasMany();
        if($hasMany) {
            foreach ($hasMany as $relationship => $class) {
                if (isset($args['Input'][$relationship])) {
                    $data = $args['Input'][$relationship];

                    /*
                     * TODO: Change the below code so that it delegates to the correct resolver types
                     */
                    /* @var $hasManyList DataList */
                    $hasManyList = $obj->$relationship();

                    $toCreate = [];
                    $update = [];
                    $updateIDs = [];
                    foreach($data as $d){
                        if(isset($d['ID']) && $d['ID']){
                            $update[$d['ID']] = $d;
                            $updateIDs[] = $d['ID'];
                        }else{
                            $toCreate[] = $d;
                        }
                    }

                    if($hasManyList && $hasManyList->count()){
                        if(!empty($updateIDs)){
                            /* @var $toUpdate DataList */
                            $toUpdate = clone $hasManyList;
                            $toUpdate = $toUpdate->filter('ID', $updateIDs);
                            foreach($toUpdate as $tu){
                                $tu->update($update[$tu->ID]);
                                $tu->write();
                            }
                        }

                        $toDelete = clone $hasManyList;
                        if(!empty($updateIDs)){
                            $toDelete = $toDelete->exclude('ID', $updateIDs);
                        }

                        foreach ($toDelete as $td) {
                            $td->delete();
                        }
                    }

                    if(!empty($toCreate)){
                        foreach($toCreate as $tc){
                            $item = Injector::inst()->create($class, $tc);
                            $hasManyList->add($item);
                        }
                    }
                }
            }
        }
    }
}
