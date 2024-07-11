<?php

declare(strict_types=1);

use kornrunner\Blurhash\Blurhash;

/**
 * BlurHash image add hash string on wp upload of new images
 *
 * @author  Opeyemi
 * @package wp-playground
 */
class BlurHashImage
{
    /** @var string */
    private string $token;

    /**
     * Register callbacks
    */
    public function register_callbacks() : void {
        add_action( 'cron_blurhash_token', [ $this, 'action_cron_blurhash_token' ] );
        add_filter( 'wp_generate_attachment_metadata', [ $this, 'filter_save_blur_hash_token' ], 10, 2 );
        add_filter( 'post_thumbnail_html', [ $this, 'filter_thumbnail_html' ], 999, 3 );
    }

    /**
     * Add cron action for blurhash
     * @param int $media_id
     *
     * @return void
    */
    public function action_cron_blurhash_token( int $media_id ): void {
        if( ! $media_id ) {
            return;
        }

        $file_url = wp_get_attachment_url( $media_id );
        if( $this->generate_blur_hash( $file_url ) ) {
            /**
             * This token is saved in a post meta and can be used on the frontend.
             * Read more here https://blurha.sh/ on how to use the code on the frontend
            */
            update_post_meta( $media_id, 'wp_blur_hash_token', $this->token );
        }
    }

    /**
     * @param string $upload
     *
     * @return bool
     */
    public function generate_blur_hash( string $upload ) : bool {
        $file_content = @file_get_contents( $upload );
        if( empty( $file_content ) ) {
            return false;
        }

        $uploaded_image = @imagecreatefromstring( $file_content );
        if( $uploaded_image === false ) {
            return false;
        }

        // We set the image at 32 cause of performance issue, if we use the uploaded image we might kill the cpu while at it.
        $max_width = 32;
        $uploaded_image = imagescale( $uploaded_image, $max_width );
        $height = imagesy( $uploaded_image );
        $width = imagesx( $uploaded_image );

        $pixels = [];
        for( $y = 0; $y < $height; ++$y ) {
            $row = [];
            for( $x = 0; $x < $width; ++$x ) {
                $index = imagecolorat( $uploaded_image, $x, $y );
                $colors = imagecolorsforindex( $uploaded_image, $index );

                $row[] = [ $colors['red'], $colors['green'], $colors['blue'] ];
            }

            $pixels[] = $row;
        }

        $encoded_hash_code = Blurhash::encode( $pixels, 4, 3 );
        $this->token = $encoded_hash_code;

        return ! empty( $this->token );
    }

    /**
     * Save token generated as meta
     *
     * @param array $metadata Metadata of the image that is generated/uploaded.
     * @param int   $attachment_id ID of the media generated.
     *
     * @return array
     */
    public function filter_save_blur_hash_token( array $metadata, int $attachment_id ): array {
        $file_url = wp_get_upload_dir()['baseurl'] . "/{$metadata['file']}";

        if( $this->generate_blur_hash( $file_url ) ) {
            update_post_meta( $attachment_id, 'wp_blur_hash_token', $this->token );
        }

        return $metadata;
    }

    /**
     * @param string $content
     * @param int    $post_id
     * @param int    $media_id
     *
     * @return string
     */
    public function filter_thumbnail_html( string $content, int $post_id, int $media_id )
    {
        if( $content !== '' ) {
            $content = $this->replace_content( $content, [ 'img' ], $media_id );
        }

        return $content;
    }

    /**
     * Add token to img data-src as attribute
     *
     * @param string $content
     * @param array  $tags
     * @param int    $media_id
     *
     * @return string
     */
    private function replace_content( string $content, array $tags, int $media_id ) : string
    {

    }
}