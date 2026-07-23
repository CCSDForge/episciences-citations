<?php

declare(strict_types=1);

namespace App\Tests\Unit\Form;

use App\Entity\Document;
use App\Entity\PaperReferences;
use App\Form\DataTransformer\JsonTransformer;
use App\Form\DocumentType;
use App\Form\PaperReferenceType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Form\Test\Traits\ValidatorExtensionTrait;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

class DocumentTypeTest extends TypeTestCase
{
    use ValidatorExtensionTrait;

    #[\Override]
    protected function getExtensions(): array
    {
        $documentType = new DocumentType();
        $paperReferenceType = new PaperReferenceType(new JsonTransformer());

        return [
            new PreloadedExtension([$documentType, $paperReferenceType], []),
            $this->getValidatorExtension(),
        ];
    }

    #[Test]
    public function testConfigureOptions_DataClass_IsDocument(): void
    {
        $form = $this->factory->create(DocumentType::class);

        $this->assertSame(Document::class, $form->getConfig()->getOption('data_class'));
    }

    #[Test]
    public function testBuildForm_ContainsExpectedFields(): void
    {
        $form = $this->factory->create(DocumentType::class);

        foreach ([
            'id',
            'paperReferences',
            'orderRef',
            'addReference',
            'addReferenceDoi',
            'addReferenceOpenAccessUrl',
            'btnModalNewReference',
            'btnCancelAddNewReference',
            'submitNewRef',
            'bibtexFile',
            'btnModalImportBibtex',
            'btnCancelImportBib',
            'submitImportBib',
            'save',
        ] as $fieldName) {
            $this->assertTrue($form->has($fieldName), "Form should have field '{$fieldName}'");
        }
    }

    #[Test]
    public function testBuildForm_UnmappedFields_AreNotMapped(): void
    {
        $form = $this->factory->create(DocumentType::class);

        $this->assertFalse($form->get('orderRef')->getConfig()->getMapped());
        $this->assertFalse($form->get('addReference')->getConfig()->getMapped());
        $this->assertFalse($form->get('addReferenceDoi')->getConfig()->getMapped());
        $this->assertFalse($form->get('addReferenceOpenAccessUrl')->getConfig()->getMapped());
        $this->assertFalse($form->get('bibtexFile')->getConfig()->getMapped());
    }

    #[Test]
    public function testSubmitValidData_WithNestedPaperReference_UpdatesExistingReference(): void
    {
        // CollectionType has no 'allow_add' option set, so it only maps submitted
        // data onto entries that already exist in the initial collection; it does
        // not create new ones. Pre-populate the document with one reference to
        // exercise the update path.
        $document = new Document();
        $document->setId(10);
        $existingReference = new PaperReferences();
        $existingReference->setId(1);
        $existingReference->setReference(['raw_reference' => 'Old reference']);
        $existingReference->setAccepted(0);
        $document->addPaperReference($existingReference);

        $formData = [
            'id' => '10',
            'paperReferences' => [
                [
                    'id' => '1',
                    'reference' => json_encode(['raw_reference' => 'Updated reference']),
                    'accepted' => '1',
                ],
            ],
            'addReference' => '',
            'addReferenceDoi' => '',
            'addReferenceOpenAccessUrl' => '',
        ];

        $form = $this->factory->create(DocumentType::class, $document);
        $form->submit($formData, false);

        $this->assertTrue($form->isSynchronized());

        $updatedDocument = $form->getData();
        $this->assertSame($document, $updatedDocument);
        $this->assertCount(1, $updatedDocument->getPaperReferences());
        $this->assertSame(
            ['raw_reference' => 'Updated reference'],
            $updatedDocument->getPaperReferences()->first()->getReference()
        );
        $this->assertSame(1, $updatedDocument->getPaperReferences()->first()->getAccepted());
    }

    #[DataProvider('provideDoiUrlSwhidCases')]
    #[Test]
    public function testIsValidDoiUrlOrSwhid_VariousInputs_ReturnsExpectedResult(string $value, bool $expected): void
    {
        $this->assertSame($expected, DocumentType::isValidDoiUrlOrSwhid($value));
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function provideDoiUrlSwhidCases(): iterable
    {
        yield 'valid doi' => ['10.1234/abcd.5678', true];
        yield 'valid doi url' => ['https://doi.org/10.1234/abcd.5678', true];
        yield 'valid http url' => ['http://example.com/paper.pdf', true];
        yield 'valid https url' => ['https://example.com/paper.pdf', true];
        yield 'valid swhid' => ['swh:1:rev:' . str_repeat('a', 40), true];
        yield 'valid swhid with qualifier' => ['swh:1:dir:' . str_repeat('a', 40) . ';origin=https://example.com', true];
        yield 'invalid plain text' => ['just some text', false];
        yield 'invalid swhid too short' => ['swh:1:rev:abc', false];
        yield 'invalid swhid bad type' => ['swh:1:xyz:' . str_repeat('a', 40), false];
        yield 'empty string' => ['', false];
    }

    #[Test]
    public function testAddReferenceDoiCallback_ValidDoi_NoViolationBuilt(): void
    {
        $constraint = $this->getCallbackConstraint('addReferenceDoi');

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects($this->never())->method('buildViolation');

        ($constraint->callback)('10.1234/abcd.5678', $context);
    }

    #[Test]
    public function testAddReferenceDoiCallback_NullValue_NoViolationBuilt(): void
    {
        $constraint = $this->getCallbackConstraint('addReferenceDoi');

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects($this->never())->method('buildViolation');

        ($constraint->callback)(null, $context);
    }

    #[Test]
    public function testAddReferenceDoiCallback_EmptyString_NoViolationBuilt(): void
    {
        $constraint = $this->getCallbackConstraint('addReferenceDoi');

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects($this->never())->method('buildViolation');

        ($constraint->callback)('', $context);
    }

    #[Test]
    public function testAddReferenceDoiCallback_InvalidValue_BuildsViolation(): void
    {
        $constraint = $this->getCallbackConstraint('addReferenceDoi');

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->expects($this->once())->method('addViolation');

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects($this->once())
            ->method('buildViolation')
            ->with('Invalid DOI, URL or SWHID format')
            ->willReturn($violationBuilder);

        ($constraint->callback)('not a valid identifier', $context);
    }

    #[Test]
    public function testAddReferenceOpenAccessUrlCallback_ValidUrl_NoViolationBuilt(): void
    {
        $constraint = $this->getCallbackConstraint('addReferenceOpenAccessUrl');

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects($this->never())->method('buildViolation');

        ($constraint->callback)('https://example.com/paper.pdf', $context);
    }

    #[Test]
    public function testAddReferenceOpenAccessUrlCallback_NullValue_NoViolationBuilt(): void
    {
        $constraint = $this->getCallbackConstraint('addReferenceOpenAccessUrl');

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects($this->never())->method('buildViolation');

        ($constraint->callback)(null, $context);
    }

    #[Test]
    public function testAddReferenceOpenAccessUrlCallback_InvalidUrl_BuildsViolation(): void
    {
        $constraint = $this->getCallbackConstraint('addReferenceOpenAccessUrl');

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->expects($this->once())->method('addViolation');

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects($this->once())
            ->method('buildViolation')
            ->with('Invalid open access link: must be an absolute http(s) URL')
            ->willReturn($violationBuilder);

        ($constraint->callback)('javascript:alert(1)', $context);
    }

    private function getCallbackConstraint(string $fieldName): Callback
    {
        $form = $this->factory->create(DocumentType::class);
        $constraints = $form->get($fieldName)->getConfig()->getOption('constraints');

        $this->assertNotEmpty($constraints);
        $this->assertInstanceOf(Callback::class, $constraints[0]);

        return $constraints[0];
    }
}
