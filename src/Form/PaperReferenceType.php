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
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
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
            'attr' => ['class' => 'tinymce'],
            'mapped' => false,
            'required' => false,
            'label' => 'modify the reference',
        ]);
        $builder->add("modifyReferenceDoi",TextType::class,[
            'attr' => ['class' => 'tinymce'],
            'mapped' => false,
            'required' => false,
            'label' => 'modify the doi',
        ]);
        $builder->add('modifyBtn', ButtonType::class,[
            'label' => 'Change reference'
        ]);
        $builder->add('acceptModifyBtn', ButtonType::class,[
            'label' => 'Confirm changes'
        ]);
        $builder->add('cancelModifyBtn', ButtonType::class,[
            'label' => 'Cancel Changes'
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
