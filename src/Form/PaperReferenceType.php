<?php


namespace App\Form;

use App\Entity\PaperReferences;
use App\Form\DataTransformer\JsonTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
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
        $builder->add("reference",TextType::class,['attr'=>['readonly'=>true]]);
        $builder->add('reference_order',HiddenType::class);
        $builder->add('accepted', ChoiceType::class, [
            'choices'  =>
                [   'Yes' => true,
                    'No' => false,
                ],
            // used to render a select box, check boxes or radios
            'expanded' => true,
            'empty_data' => null,
            'data' => 1, // checked by default
            'placeholder' => false,
            'required' => false,
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
