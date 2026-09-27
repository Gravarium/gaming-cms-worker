<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Guild;
use App\Entity\GuildMember;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<GuildOnboardingTaskInput> */
final class GuildOnboardingTaskType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $guild = $options['guild'];
        $builder
            ->add('label', TextType::class, [
                'label' => 'Onboarding-Aufgabe',
                'attr' => ['maxlength' => 180],
            ])
            ->add('member', EntityType::class, [
                'class' => GuildMember::class,
                'label' => 'Gildenmitglied',
                'choice_label' => static fn (GuildMember $member): string => $member->getCharacterName().' · '.$member->getDisplayRank(),
                'placeholder' => 'Mitglied auswählen',
                'query_builder' => static fn (EntityRepository $repository) => $repository->createQueryBuilder('guildMember')
                    ->andWhere('guildMember.guild = :guild')
                    ->andWhere('guildMember.active = true')
                    ->setParameter('guild', $guild)
                    ->orderBy('guildMember.position', 'ASC')
                    ->addOrderBy('guildMember.characterName', 'ASC'),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => GuildOnboardingTaskInput::class]);
        $resolver->setRequired('guild');
        $resolver->setAllowedTypes('guild', Guild::class);
    }
}
