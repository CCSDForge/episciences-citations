<?php


namespace App\Form;

use App\Entity\Document;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ButtonType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;


class DocumentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('id',HiddenType::class);
        $builder->add('paperReferences',CollectionType::class,[
            'entry_type' => PaperReferenceType::class,
        ]);
        $builder->add('orderRef', HiddenType::class, ['attr' => ['data-order-ref' => ''],'mapped' => false]);
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
        $builder->add('submitNewRef',SubmitType::class,[
            'label' => 'Add reference',
        ]);
        $builder->add('btnModalNewReference',ButtonType::class,[ 'label' => 'Add reference','row_attr' => ['class'=>'w-1/2']]);
        $builder->add('btnCancelAddNewReference',ButtonType::class,['label' => 'Cancel']);
        $builder->add('save',SubmitType::class, ['row_attr' => ['class'=>'w-1/2']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Document::class,
        ]);
    }
}
