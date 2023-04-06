<?php


namespace App\Form;

use App\Entity\PaperReferences;
use Doctrine\DBAL\Types\TextType;
use phpDocumentor\Reflection\Types\Collection;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;


class ReferenceInformationsFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('reference',TextType::class, [
        ]);
        $builder->add('accepted',ChoiceType::class, [
            'required'=> true,
            'expanded'=> true,
            'multiple'=> false,
            'choices'  => [
                'accepté'   => true,
                'refusé'    => false,
            ],
            'attr' => ["class" => "accent-emerald-500"],
            'mapped' => false
        ]);
        $builder->add('reference_order',NumberType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'    => PaperReferences::class,
            'data'          => null
        ]);
        $resolver->setRequired(['data']);
    }
}
