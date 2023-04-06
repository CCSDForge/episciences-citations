<?php


namespace App\Form;

use App\Entity\PaperReferences;
use App\Form\ReferenceInformationsFormType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;


class ReferencesFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $choice = [];
        if (!empty($options['data'])) {
            foreach ($options['data'] as $key => $data) {
                    /** @var PaperReferences $data */
                    $refInfo = json_decode($data->getReference()[0], true, 512, JSON_THROW_ON_ERROR);
                    $index = $data->getId();
                    $choice[$index] = (int)$data->getId();
                }
            }
        $builder->add('choice',ChoiceType::class, [
            'choice_attr' => ['data-class'=>"toto"],
            'required'=>false,
            'expanded'=>true,
            'multiple'=>true,
            'choices'  => $choice
        ]);

        $builder->add('save',SubmitType::class,['attr' => ['class' => 'save']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data' => null
        ]);
    }
}
