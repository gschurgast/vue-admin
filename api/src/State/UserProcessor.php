<?php

namespace App\State;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private RequestStack $requestStack,
        private string $projectDir,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): User
    {
        if (!$data instanceof User) {
            throw new \InvalidArgumentException('Expected User entity');
        }

        $request = $this->requestStack->getCurrentRequest();
        $isPictureEndpoint = $request && str_contains($request->getPathInfo(), '/picture');

        // Handle picture DELETE
        if ($isPictureEndpoint && $operation instanceof Delete) {
            $this->deletePicture($data);
            $this->entityManager->flush();
            return $data;
        }

        // Handle picture upload POST
        if ($isPictureEndpoint && $operation instanceof Post) {
            if ($request->files->has('picture')) {
                $file = $request->files->get('picture');
                if ($file instanceof UploadedFile) {
                    $this->handlePictureUpload($data, $file);
                }
            }
            $this->entityManager->flush();
            return $data;
        }

        // Handle password hashing for regular user operations
        if ($data->getPlainPassword()) {
            $data->setPassword($this->passwordHasher->hashPassword($data, $data->getPlainPassword()));
            $data->eraseCredentials();
        }

        // Handle picture deletion via PATCH (when picture is set to null)
        if ($request) {
            $content = $request->getContent();
            if ($content) {
                $jsonData = json_decode($content, true);
                if (is_array($jsonData) && array_key_exists('picture', $jsonData) && $jsonData['picture'] === null) {
                    $this->deletePicture($data);
                }
            }
        }

        if ($operation instanceof Post) {
            $this->entityManager->persist($data);
        }

        $this->entityManager->flush();

        return $data;
    }

    private function handlePictureUpload(User $user, UploadedFile $file): void
    {
        // Validate file size (max 5MB)
        if ($file->getSize() > 5 * 1024 * 1024) {
            throw new \InvalidArgumentException('File too large. Maximum size: 5MB');
        }

        // Allowed MIME types and their extensions
        $allowedTypes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];

        // Validate MIME type
        $mimeType = $file->getMimeType();
        if (!isset($allowedTypes[$mimeType])) {
            throw new \InvalidArgumentException('Invalid file type. Allowed: JPEG, PNG, GIF, WebP');
        }

        // Verify it's actually a valid image using getimagesize
        $imageInfo = @getimagesize($file->getPathname());
        if ($imageInfo === false) {
            throw new \InvalidArgumentException('Invalid image file');
        }

        // Double-check the image type matches
        $imageTypeToMime = [
            IMAGETYPE_JPEG => 'image/jpeg',
            IMAGETYPE_PNG => 'image/png',
            IMAGETYPE_GIF => 'image/gif',
            IMAGETYPE_WEBP => 'image/webp',
        ];
        if (!isset($imageTypeToMime[$imageInfo[2]]) || $imageTypeToMime[$imageInfo[2]] !== $mimeType) {
            throw new \InvalidArgumentException('Image type mismatch');
        }

        // Delete old picture if exists
        $this->deleteOldPicture($user);

        // Use the extension from our allowed list (not from user input)
        $extension = $allowedTypes[$mimeType];
        $filename = sprintf('profile_%d_%s.%s', $user->getId(), bin2hex(random_bytes(8)), $extension);

        // Move file to public uploads directory
        $uploadDir = $this->projectDir . '/public/uploads/profiles';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $file->move($uploadDir, $filename);

        // Update user with new picture path
        $user->setPicture('/uploads/profiles/' . $filename);
    }

    private function deletePicture(User $user): void
    {
        $this->deleteOldPicture($user);
        $user->setPicture(null);
    }

    private function deleteOldPicture(User $user): void
    {
        $oldPicture = $user->getPicture();
        if ($oldPicture) {
            $uploadDir = $this->projectDir . '/public/uploads/profiles';
            $oldPath = $this->projectDir . '/public' . $oldPicture;
            $realOldPath = realpath($oldPath);
            $realUploadDir = realpath($uploadDir);

            // Only delete if the file is within the uploads/profiles directory
            if ($realOldPath && $realUploadDir && str_starts_with($realOldPath, $realUploadDir)) {
                unlink($realOldPath);
            }
        }
    }
}
