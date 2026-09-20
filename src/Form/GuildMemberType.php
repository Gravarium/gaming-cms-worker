<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Guild;
use App\Entity\GuildMember;
use App\Entity\GuildRank;
use App\Entity\User;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<GuildMember> */
final class GuildMemberType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $guild = $options['guild'];
        $builder
            ->add('user', EntityType::class, ['class' => User::class, 'choice_label' => 'email', 'label' => 'CMS-Benutzerkonto', 'placeholder' => 'Kein Konto verknüpfen', 'required' => false])
            ->add('characterName', null, ['label' => 'Charaktername'])
            ->add('playerName', null, ['label' => 'Spielername', 'required' => false])
            ->add('rank', EntityType::class, [
                'class' => GuildRank::class,
                'choice_label' => 'name',
                'label' => 'Gildenrang',
                'placeholder' => 'Kein eigener Rang',
                'required' => false,
                'query_builder' => static fn (EntityRepository $repository) => $repository->createQueryBuilder('rank')->andWhere('rank.guild = :guild')->setParameter('guild', $guild)->orderBy('rank.position', 'ASC'),
            ])
            ->add('rankName', null, ['label' => 'Freier Rangname (Fallback)', 'required' => false])
            ->add('characterClass', null, ['label' => 'Klasse oder Rolle', 'required' => false])
            ->add('characterLevel', IntegerType::class, ['label' => 'Stufe', 'required' => false])
            ->add('position', IntegerType::class, ['label' => 'Sortierung'])
            ->add('leader', CheckboxType::class, ['label' => 'Gildenleitung', 'required' => false])
            ->add('active', CheckboxType::class, ['label' => 'Aktiv und öffentlich sichtbar', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => GuildMember::class]);
        $resolver->setRequired('guild');
        $resolver->setAllowedTypes('guild', Guild::class);
    }
}
