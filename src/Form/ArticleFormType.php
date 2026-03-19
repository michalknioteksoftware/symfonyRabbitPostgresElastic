<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Article;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class ArticleFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label'       => 'Title',
                'attr'        => ['placeholder' => 'Article title…', 'autofocus' => true],
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Length(min: 3, max: 255),
                ],
            ])
            ->add('content', TextareaType::class, [
                'label' => 'Content',
                'attr'  => ['placeholder' => 'Write your article content…', 'rows' => 10],
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Length(min: 10),
                ],
            ])
            // Tags are stored as a JSON array. The user enters a comma-separated string;
            // the controller converts it to an array after the form validates.
            // Validation lives here (on the visible field) so errors attach directly to
            // this input — entity-level $tags errors would silently fall to the form root.
            ->add('tagsRaw', TextType::class, [
                'label'    => 'Tags',
                'mapped'   => false,
                'required' => false,
                'attr'     => ['placeholder' => 'php, symfony, backend'],
                'constraints' => [
                    new Assert\Callback(function (mixed $value, ExecutionContextInterface $ctx): void {
                        if (trim((string) $value) === '') {
                            return; // tags are optional
                        }

                        $tags = array_values(array_filter(
                            array_map('trim', explode(',', (string) $value)),
                            fn (string $t) => $t !== ''
                        ));

                        if (count($tags) > 15) {
                            $ctx->buildViolation('You can add a maximum of 15 tags.')
                                ->addViolation();
                            return;
                        }

                        foreach ($tags as $tag) {
                            if (mb_strlen($tag) > 50) {
                                $ctx->buildViolation('Each tag must be 50 characters or less (got "{{ tag }}").')
                                    ->setParameter('{{ tag }}', $tag)
                                    ->addViolation();
                                return;
                            }

                            if (!preg_match('/^[a-zA-Z0-9\-_]+$/', $tag)) {
                                $ctx->buildViolation(
                                    'Tag "{{ tag }}" is invalid — only letters, numbers, hyphens, and underscores are allowed.'
                                )
                                    ->setParameter('{{ tag }}', $tag)
                                    ->addViolation();
                                return;
                            }
                        }
                    }),
                ],
            ]);

    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Article::class,
        ]);
    }
}
