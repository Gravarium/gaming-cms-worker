<?php

declare(strict_types=1);
namespace App\Form;
use App\Entity\Category;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
/** @extends AbstractType<Category> */
final class CategoryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', null, ['label' => 'Name', 'attr' => ['maxlength' => 100]])
            ->add('parent', EntityType::class, [
                'label' => 'Übergeordnete Kategorie',
                'class' => Category::class,
                'choice_label' => 'displayName',
                'placeholder' => 'Keine, oberste Ebene',
                'required' => false,
                'mapped' => false,
                'data' => $builder->getData() instanceof Category ? $builder->getData()->getParent() : null,
            ])
            ->add('description', TextareaType::class, ['label' => 'Beschreibung', 'required' => false, 'attr' => ['rows' => 4, 'maxlength' => 500]]);
    }
    public function configureOptions(OptionsResolver $resolver): void { $resolver->setDefaults(['data_class' => Category::class]); }
}
