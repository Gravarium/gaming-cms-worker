<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Guild;
use App\Entity\GuildMember;
use App\Entity\GuildTeam;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<GuildTeam> */
final class GuildTeamType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $guild = $options['guild'];
        $members = static fn (EntityRepository $repository) => $repository->createQueryBuilder('guildMember')->andWhere('guildMember.guild = :guild')->setParameter('guild', $guild)->orderBy('guildMember.characterName', 'ASC');

        $builder->add('name', null, ['label' => 'Teamname', 'attr' => ['maxlength' => 120]])
            ->add('description', TextareaType::class, ['label' => 'Beschreibung', 'required' => false])
            ->add('color', null, ['label' => 'Farbe', 'required' => false, 'attr' => ['placeholder' => '#7c5cff']])
            ->add('leader', EntityType::class, ['class' => GuildMember::class, 'choice_label' => 'characterName', 'query_builder' => $members, 'placeholder' => 'Keine Teamleitung', 'required' => false])
            ->add('members', EntityType::class, ['class' => GuildMember::class, 'choice_label' => 'characterName', 'query_builder' => $members, 'multiple' => true, 'expanded' => true, 'required' => false, 'label' => 'Teammitglieder'])
            ->add('active', CheckboxType::class, ['label' => 'Team aktiv', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => GuildTeam::class])->setRequired('guild')->setAllowedTypes('guild', Guild::class);
    }
}
