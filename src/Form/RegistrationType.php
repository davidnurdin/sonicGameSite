<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** Quick sign-up: a name (the one of the game), a password, an email if the player wants. */
class RegistrationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('username', TextType::class, [
                'label' => 'Ton nom de joueur',
                'help' => '3 à 15 lettres ou chiffres : le nom affiché dans les parties en ligne.',
                'attr' => ['maxlength' => 15, 'autocomplete' => 'username', 'autocapitalize' => 'characters',
                    'spellcheck' => 'false', 'pattern' => '[A-Za-z0-9]{3,15}', 'autofocus' => true],
            ])
            ->add('plainPassword', PasswordType::class, [
                'label' => 'Mot de passe',
                'mapped' => false,
                'attr' => ['autocomplete' => 'new-password', 'minlength' => 6],
                'constraints' => [
                    new Assert\NotBlank(message: 'Choisis un mot de passe.'),
                    new Assert\Length(min: 6, max: 4096, minMessage: 'Au moins {{ limit }} caractères.'),
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email (facultatif)',
                'required' => false,
                'help' => 'Pour te prévenir des nouveautés (éditeur de niveau…). Jamais affiché.',
                'attr' => ['autocomplete' => 'email', 'maxlength' => 180, 'inputmode' => 'email'],
            ])
            // honeypot: hidden from people (CSS), filled by the bots
            ->add('website', TextType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'Ne pas remplir',
                'row_attr' => ['class' => 'hp', 'aria-hidden' => 'true'],
                'attr' => ['tabindex' => '-1', 'autocomplete' => 'off'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => User::class]);
    }
}
