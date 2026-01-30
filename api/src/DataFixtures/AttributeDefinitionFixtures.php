<?php

namespace App\DataFixtures;

use App\Entity\AttributeDefinition;
use App\Enum\AttributeType;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class AttributeDefinitionFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        // Largeur (en cm)
        $width = (new AttributeDefinition())
            ->setCode('width_cm')
            ->setType(AttributeType::NUMBER)
            ->setIsLocalizable(false)
            ->setIsScopable(false)
            ->setUnit('cm')
            ->setIsRequired(true)
            ->setSortOrder(10)
            ->setValidationRules([
                'min' => 10,
                'max' => 500
            ]);
        $manager->persist($width);

        // Hauteur (en cm)
        $height = (new AttributeDefinition())
            ->setCode('height_cm')
            ->setType(AttributeType::NUMBER)
            ->setIsLocalizable(false)
            ->setIsScopable(false)
            ->setUnit('cm')
            ->setIsRequired(true)
            ->setSortOrder(20)
            ->setValidationRules([
                'min' => 10,
                'max' => 300
            ]);
        $manager->persist($height);

        // Profondeur (en cm)
        $depth = (new AttributeDefinition())
            ->setCode('depth_cm')
            ->setType(AttributeType::NUMBER)
            ->setIsLocalizable(false)
            ->setIsScopable(false)
            ->setUnit('cm')
            ->setIsRequired(true)
            ->setSortOrder(30)
            ->setValidationRules([
                'min' => 10,
                'max' => 300
            ]);
        $manager->persist($depth);

        // Poids (en kg)
        $weight = (new AttributeDefinition())
            ->setCode('weight_kg')
            ->setType(AttributeType::NUMBER)
            ->setIsLocalizable(false)
            ->setIsScopable(false)
            ->setUnit('kg')
            ->setIsRequired(false)
            ->setSortOrder(40)
            ->setValidationRules([
                'min' => 1,
                'max' => 300
            ]);
        $manager->persist($weight);

        // Couleur marketing (enum localisable)
        $color = (new AttributeDefinition())
            ->setCode('color_marketing')
            ->setType(AttributeType::ENUM)
            ->setIsLocalizable(true)
            ->setIsScopable(false)
            ->setIsRequired(true)
            ->setSortOrder(50)
            ->setAllowedValues([
                "Blanc",
                "Noir",
                "Bleu nuit",
                "Gris perle",
                "Rouge bordeaux"
            ]);
        $manager->persist($color);

        // Matière
        $material = (new AttributeDefinition())
            ->setCode('material')
            ->setType(AttributeType::TEXT)
            ->setIsLocalizable(true)
            ->setIsScopable(false)
            ->setIsRequired(true)
            ->setSortOrder(60)
            ->setValidationRules([
                'minLength' => 2,
                'maxLength' => 50
            ]);
        $manager->persist($material);

        // Description technique (localisable)
        $technicalDesc = (new AttributeDefinition())
            ->setCode('technical_description')
            ->setType(AttributeType::TEXTAREA)
            ->setIsLocalizable(true)
            ->setIsScopable(false)
            ->setIsRequired(false)
            ->setSortOrder(70);
        $manager->persist($technicalDesc);

        // Garantie en années (scopable par pays)
        $warranty = (new AttributeDefinition())
            ->setCode('warranty_years')
            ->setType(AttributeType::NUMBER)
            ->setIsLocalizable(false)
            ->setIsScopable(true)
            ->setIsRequired(false)
            ->setSortOrder(80)
            ->setValidationRules([
                'min' => 1,
                'max' => 10
            ]);
        $manager->persist($warranty);

        // Nécessite montage ?
        $needsAssembly = (new AttributeDefinition())
            ->setCode('assembly_required')
            ->setType(AttributeType::BOOLEAN)
            ->setIsLocalizable(false)
            ->setIsScopable(false)
            ->setIsRequired(true)
            ->setSortOrder(90)
            ->setDefaultValue('false');
        $manager->persist($needsAssembly);

        // Image principale (media)
        $mainImage = (new AttributeDefinition())
            ->setCode('main_image')
            ->setType(AttributeType::MEDIA)
            ->setIsLocalizable(false)
            ->setIsScopable(false)
            ->setIsRequired(true)
            ->setSortOrder(100);
        $manager->persist($mainImage);

        $manager->flush();
    }
}
