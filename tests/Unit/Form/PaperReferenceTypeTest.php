<?php

declare(strict_types=1);

namespace App\Tests\Unit\Form;

use App\Entity\PaperReferences;
use App\Form\DataTransformer\JsonTransformer;
use App\Form\PaperReferenceType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Form\Test\TypeTestCase;

class PaperReferenceTypeTest extends TypeTestCase
{
    #[\Override]
    protected function getExtensions(): array
    {
        $type = new PaperReferenceType(new JsonTransformer());

        return [
            new PreloadedExtension([$type], []),
        ];
    }

    #[Test]
    public function testConfigureOptions_DataClass_IsPaperReferences(): void
    {
        $form = $this->factory->create(PaperReferenceType::class);

        $this->assertSame(PaperReferences::class, $form->getConfig()->getOption('data_class'));
    }

    #[Test]
    public function testBuildForm_ContainsExpectedFields(): void
    {
        $form = $this->factory->create(PaperReferenceType::class);

        foreach (['id', 'reference', 'accepted', 'checkboxIdTodelete', 'isDirtyTextAreaModifyRef'] as $fieldName) {
            $this->assertTrue($form->has($fieldName), "Form should have field '{$fieldName}'");
        }
    }

    #[Test]
    public function testBuildForm_UnmappedFields_AreNotMapped(): void
    {
        $form = $this->factory->create(PaperReferenceType::class);

        $this->assertFalse($form->get('checkboxIdTodelete')->getConfig()->getMapped());
        $this->assertFalse($form->get('isDirtyTextAreaModifyRef')->getConfig()->getMapped());
    }

    #[Test]
    public function testBuildForm_ReferenceField_HasJsonModelTransformerAttached(): void
    {
        $form = $this->factory->create(PaperReferenceType::class);

        $transformers = $form->get('reference')->getConfig()->getModelTransformers();

        $this->assertCount(1, $transformers);
        $this->assertInstanceOf(JsonTransformer::class, $transformers[0]);
    }

    #[Test]
    public function testSubmitValidData_BuildsPaperReferencesWithDecodedReference(): void
    {
        $formData = [
            'id' => '5',
            'reference' => json_encode(['raw_reference' => 'Doe, J. (2021). Title.', 'doi' => '10.1/x']),
            'accepted' => '1',
        ];

        $form = $this->factory->create(PaperReferenceType::class);
        $form->submit($formData);

        $this->assertTrue($form->isSynchronized());

        $object = $form->getData();
        $this->assertInstanceOf(PaperReferences::class, $object);
        $this->assertSame(5, $object->getId());
        $this->assertSame(1, $object->getAccepted());
        $this->assertSame(
            ['raw_reference' => 'Doe, J. (2021). Title.', 'doi' => '10.1/x'],
            $object->getReference()
        );
    }

    #[Test]
    public function testSubmitData_EmptyReference_ResultsInEmptyArray(): void
    {
        $formData = [
            'id' => '5',
            'reference' => '',
            'accepted' => '0',
        ];

        $form = $this->factory->create(PaperReferenceType::class);
        $form->submit($formData);

        $this->assertTrue($form->isSynchronized());

        $object = $form->getData();
        $this->assertSame([], $object->getReference());
    }

    #[Test]
    public function testSetData_ArrayReference_TransformsToJsonViewData(): void
    {
        $entity = new PaperReferences();
        $entity->setId(1);
        $entity->setReference(['raw_reference' => 'Existing reference']);
        $entity->setAccepted(1);

        $form = $this->factory->create(PaperReferenceType::class, $entity);

        $this->assertJsonStringEqualsJsonString(
            json_encode(['raw_reference' => 'Existing reference']),
            (string) $form->get('reference')->getViewData()
        );
    }
}
