<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Document;
use App\Entity\PaperReferences;
use App\Services\OpenAccess\OpenAccessUrlSanitizer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ButtonType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Context\ExecutionContextInterface;


/** @extends AbstractType<Document> */
class DocumentType extends AbstractType
{
    private const HALF_WIDTH_ROW_ATTR = ['class' => 'w-1/2'];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('id',HiddenType::class);
        $builder->add('paperReferences',CollectionType::class,[
            'entry_type' => PaperReferenceType::class,
            'label' => false,
        ]);
        $builder->add('orderRef', HiddenType::class, ['attr' => ['data-order-ref' => ''],'mapped' => false]);
        //Add new references
        $builder->add("addReference",TextareaType::class,[
            'attr' => ['class' => 'shadow appearance-none border border-gray-300 rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline'],
            'mapped' => false,
            'required' => false,
            'label' => 'Reference',
        ]);
        $builder->add("addReferenceDoi",TextType::class,[
            'attr' => ['class' => 'shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline'],
            'mapped' => false,
            'required' => false,
            'label' => 'DOI, URL, SWHID',
            'constraints' => [
                new Callback(static function (?string $value, ExecutionContextInterface $context): void {
                    if ($value === null || $value === '') {
                        return;
                    }
                    if (!self::isValidDoiUrlOrSwhid($value)) {
                        $context->buildViolation('Invalid DOI, URL or SWHID format')->addViolation();
                    }
                }),
            ],
        ]);
        $builder->add("addReferenceOpenAccessUrl",TextType::class,[
            'attr' => ['class' => 'shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline'],
            'mapped' => false,
            'required' => false,
            'label' => 'Open access link',
            'constraints' => [
                new Callback(static function (?string $value, ExecutionContextInterface $context): void {
                    if ($value === null || $value === '') {
                        return;
                    }
                    if (OpenAccessUrlSanitizer::sanitize($value) === null) {
                        $context->buildViolation('Invalid open access link: must be an absolute http(s) URL')->addViolation();
                    }
                }),
            ],
        ]);
        $builder->add('btnModalNewReference',ButtonType::class,
            [ 'label' => 'Add reference','row_attr' => self::HALF_WIDTH_ROW_ATTR]);
        $builder->add('btnCancelAddNewReference',ButtonType::class,['label' => 'Cancel']);
        $builder->add('submitNewRef',SubmitType::class,[
            'label' => 'Add reference',
        ]);
        // import bibTEX
        $builder->add('bibtexFile',FileType::class,[
            'mapped' => false,
            'required' => false,
            'constraints' => [
                new File(mimeTypes: [
                    'text/plain',
                    'text/x-bibtex',
                ], mimeTypesMessage: 'Please upload a valid BibTeX document')
            ],
        ]);
        $builder->add('btnModalImportBibtex',ButtonType::class,
            ['label' => 'Import BibTeX','row_attr' => self::HALF_WIDTH_ROW_ATTR]);
        $builder->add('btnCancelImportBib',ButtonType::class,['label' => 'Cancel']);
        $builder->add('submitImportBib',SubmitType::class,[
            'label' => 'Import',
        ]);
        $builder->add('save',SubmitType::class, ['row_attr' => self::HALF_WIDTH_ROW_ATTR]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Document::class,
        ]);
    }

    public static function isValidDoiUrlOrSwhid(string $value): bool
    {
        return self::isDoi($value) || self::isUrl($value) || self::isSwhid($value);
    }

    private static function isDoi(string $value): bool
    {
        // DOI: 10.digits[.digits]*/suffix (also matches https://doi.org/10.xxx/yyy)
        return (bool) preg_match('/^(?:https?:\/\/(?:dx\.)?doi\.org\/)?10\.\d{4,}(?:\.\d+)*\/\S+$/i', $value);
    }

    private static function isUrl(string $value): bool
    {
        return (bool) preg_match('/^https?:\/\/\S+/i', $value);
    }

    private static function isSwhid(string $value): bool
    {
        // SWHID: swh:1:(snp|rel|rev|dir|cnt):<40 hex chars>[;qualifier=value...]
        return (bool) preg_match('/^swh:1:(snp|rel|rev|dir|cnt):[0-9a-f]{40}(;(origin|visit|anchor|path|lines|bytes)=[^;]+)*$/', $value);
    }
}
