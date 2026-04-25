<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\PaperReferences;
use App\Form\DataTransformer\JsonTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;


class PaperReferenceType extends AbstractType
{
    public function __construct(
        private readonly JsonTransformer $jsonTransformer,
    ) {
    }
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('id', HiddenType::class);
        $builder->add('reference', TextType::class);
        $builder->add('accepted', HiddenType::class, [
            'empty_data' => '0',
        ]);
        $builder->add('checkboxIdTodelete', CheckboxType::class, [
            'label'    => false,
            'required' => false,
            'mapped'   => false,
        ]);
        $builder->add('isDirtyTextAreaModifyRef', HiddenType::class, [
            'mapped'   => false,
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
