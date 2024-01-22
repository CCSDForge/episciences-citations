<?php


namespace App\Form;

use App\Entity\PaperReferences;
use App\Form\DataTransformer\JsonTransformer;
use ModifyReferenceType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ButtonType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\SubmitButton;
use Symfony\Component\OptionsResolver\OptionsResolver;


class PaperReferenceType extends AbstractType
{
    public function __construct(
        private JsonTransformer $jsonTransformer,
    ) {
    }
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {

        $builder->add('id',HiddenType::class);
        $builder->add("reference",TextType::class);
        $builder->add('reference_order',HiddenType::class);
        $builder->add('accepted', ChoiceType::class, [
            'choices'  =>
                [   'Yes' => true,
                    'No' => false,
                ],
            // used to render a select box, check boxes or radios
            'expanded' => true,
            'empty_data' => "0",
            'placeholder' => false,
            'required' => false,
        ]);
        $builder->add("modifyReference",TextareaType::class,[
            'attr' => ['class' => 'tinymce shadow appearance-none border border-gray-300 rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline'],
            'mapped' => false,
            'required' => false,
            'label' => false,
        ]);
        $builder->add("modifyReferenceDoi",TextType::class,[
            'attr' => ['class' => 'tinymce shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline','placeholder' => "DOI, URL"],
            'mapped' => false,
            'required' => false,
            'label' => false,
        ]);

        $builder->add('modifyBtn', ButtonType::class,[
            'label' => 'Edit'
        ]);
        $builder->add('acceptModifyBtn', ButtonType::class,[
            'label' => 'Confirm'
        ]);
        $builder->add('cancelModifyBtn', ButtonType::class,[
            'label' => 'Cancel'
        ]);
        $builder->get('reference')
            ->addModelTransformer($this->jsonTransformer);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PaperReferences::class,
        ]);
    }
}
