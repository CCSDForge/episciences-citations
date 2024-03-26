<?php


namespace App\Form;

use App\Entity\Document;
use App\Entity\PaperReferences;
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
use Symfony\Component\Validator\Constraints\File;


class DocumentType extends AbstractType
{
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
            'attr' => ['class' => 'tinymce shadow appearance-none border border-gray-300 rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline'],
            'mapped' => false,
            'required' => false,
            'label' => 'Reference',
        ]);
        $builder->add("addReferenceDoi",TextType::class,[
            'attr' => ['class' => 'tinymce shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline'],
            'mapped' => false,
            'required' => false,
            'label' => 'DOI, URL, ...',
        ]);
        $builder->add('btnModalNewReference',ButtonType::class,
            [ 'label' => 'Add reference','row_attr' => ['class'=>'w-1/2']]);
        $builder->add('btnCancelAddNewReference',ButtonType::class,['label' => 'Cancel']);
        $builder->add('submitNewRef',SubmitType::class,[
            'label' => 'Add reference',
        ]);
        // import bibTEX
        $builder->add('bibtexFile',FileType::class,[
            'mapped' => false,
            'required' => false,
            'constraints' => [
                new File([
                    'mimeTypes' => [
                        'text/plain',
                        'text/x-bibtex',
                    ],
                    'mimeTypesMessage' => 'Please upload a valid BibTeX document',
                ])
            ],
        ]);
        $builder->add('btnModalImportBibtex',ButtonType::class,
            ['label' => 'Import BibTeX','row_attr' => ['class'=>'w-1/2']]);
        $builder->add('btnCancelImportBib',ButtonType::class,['label' => 'Cancel']);
        $builder->add('submitImportBib',SubmitType::class,[
            'label' => 'Import',
        ]);
        $builder->add('save',SubmitType::class, ['row_attr' => ['class'=>'w-1/2']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Document::class,
        ]);
    }
}
