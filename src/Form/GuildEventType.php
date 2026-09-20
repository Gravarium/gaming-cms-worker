<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Guild;
use App\Entity\GuildEvent;
use App\Entity\GuildTeam;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<GuildEvent> */
final class GuildEventType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $guild = $options['guild'];
        $builder->add('title', null, ['label' => 'Titel'])
            ->add('team', EntityType::class, [
                'class' => GuildTeam::class,
                'choice_label' => 'name',
                'label' => 'Team',
                'placeholder' => 'Für die gesamte Gilde',
                'required' => false,
                'query_builder' => static fn (EntityRepository $repository) => $repository->createQueryBuilder('team')->andWhere('team.guild = :guild')->andWhere('team.active = true')->setParameter('guild', $guild)->orderBy('team.name', 'ASC'),
            ])
            ->add('type', ChoiceType::class, ['label' => 'Art', 'choices' => ['Raid' => 'raid', 'Besprechung' => 'meeting', 'Training' => 'training', 'Sonstiges' => 'other']])
            ->add('description', TextareaType::class, ['label' => 'Beschreibung', 'attr' => ['rows' => 7]])
            ->add('startsAt', DateTimeType::class, ['label' => 'Beginn', 'widget' => 'single_text'])
            ->add('endsAt', DateTimeType::class, ['label' => 'Ende', 'widget' => 'single_text', 'required' => false])
            ->add('maxParticipants', IntegerType::class, ['label' => 'Teilnehmerlimit', 'required' => false])
            ->add('location', null, ['label' => 'Server, Treffpunkt oder Kanal', 'required' => false])
            ->add('status', ChoiceType::class, ['label' => 'Status', 'choices' => ['Geplant' => 'planned', 'Abgeschlossen' => 'done', 'Abgesagt' => 'cancelled']]);
    }
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => GuildEvent::class])->setRequired('guild')->setAllowedTypes('guild', Guild::class);
    }
}
