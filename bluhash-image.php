<?php

declare(strict_types=1);

use kornrunner\Blurhash\Blurhash;

/**
 * BlurHash image add hash string on wp upload of new images
 *
 * @author  Opeyemi
 * @package wp-playground
 *
 * TODO:: 1 -- Add option to generate for existing images, 2 -- Make this into a plugin, 3 -- Add lint, tests
 */
class Blur_Hash_Image
{
    /** @var string */
    private string $token;

    /**
     * Register callbacks
    */
    public function register_callbacks() : void {
        add_action( 'cron_blur_hash_token', [ $this, 'action_cron_blur_hash_token' ] );
        add_filter( 'wp_generate_attachment_metadata', [ $this, 'filter_save_blur_hash_token' ], 10, 2 );
        add_filter( 'post_thumbnail_html', [ $this, 'filter_thumbnail_html' ], 999, 3 );
        add_filter( 'wp_get_attachment_image_attributes', [ $this, 'filter_add_blur_hash_attributes' ], 999, 2 );
    }

    /**
     * Add cron action for blurhash
     * @param int $media_id
     *
     * @return void
    */
    public function action_cron_blur_hash_token( int $media_id ): void {
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
    public function filter_thumbnail_html( string $content, int $post_id, int $media_id ) : string {
        if( $content !== '' ) {
            $content = $this->replace_content( $content, [ 'img' ], $media_id );
        }

        return $content;
    }

    /**
     * Add blur hash token to img data-src as attribute
     *
     * @param string $content
     * @param array  $tags
     * @param int    $media_id
     *
     * @return string
     */
    private function replace_content( string $content, array $tags, int $media_id ) : string {
        foreach( $tags as $tag ) {
            $content = $this->image_tag_replace( $content, $tag, $media_id );
        }

        return $content;
    }

    /**
     * @param string $content
     * @param string $tag
     * @param int    $media_id
     *
     * @return string
     */
    private function image_tag_replace( string $content, string $tag, int $media_id ) : string {
        if( $media_id <= 0 ) {
            return $content;
        }

        $new_content = $content;
        $closing_tag = $this->get_tag_end( $tag );
        preg_match_all( sprintf( '/<%1$s[\s]*[^<]*%2$s>/is', $tag, $closing_tag ), $content, $matches );

        if( count( $matches[0] ) ) {
            foreach( $matches[0] as $match ) {
                $replace_attr_result = $this->replace_attribute( $match, $tag );
                $new_markup          = $replace_attr_result[0];
                $blur_hash_token     = $this->get_blur_hash_token( $media_id );

                if( ! empty( $blur_hash_token ) ) {
                    $blur_hash_data = $this->set_blur_hash_attribute( $blur_hash_token, $new_markup, $tag );
                    $new_content    = str_replace( $match, $blur_hash_data, $content );
                }
            }
        }

        return $new_content;
    }

    /**
     * @param string $match
     * @param string $tag
     *
     * @return array
     */
    private function replace_attribute( string $match, string $tag ) : array {
        $src_attr = [];
        $had_src = preg_match( '@src="([^"]+)"@', $match, $src_attr );
        $attrs = [ 'src', 'srcset' ];

        foreach( $attrs as $attr ) {
            if( ! preg_match( sprintf( '/<%1$s[^>]*[\s]data-%2$s=/', $tag, $attr ), $match ) ) {
                $match = preg_replace( sprintf( '/(<%1$s[^>]*)[\s]%2$s=/', $tag, $attr ), sprintf( '$1 data-%s=', $attr ), $match );
            }
        }

        return [ $match, $had_src === 1 ? $src_attr[1] : false ];
    }


    /**
     * @param string $tag
     *
     * @return string
     */
    private function get_tag_end( string $tag ) : string {
        return ( in_array( $tag, [ 'img', 'embed', 'source' ], true ) ) ? '\/?' : '>.*?\s*<\/' . $tag;
    }


    /**
     * Set data-blush attribute
     *
     * @param string $token
     * @param string $markup
     * @param string $tag
     *
     * @return string
     * */
    private function set_blur_hash_attribute( string $token, string $markup, string $tag ) : string {
        return str_replace(
            sprintf( '<%s', $tag ),
            '<' . $tag . sprintf( ' data-blurhash=%1$s', htmlspecialchars( $token ) ),
            $markup
        );
    }


    /**
     * Add Blur hash attribute to wp attachment
     *
     * @param array    $attr
     * @param \WP_Post $attachment
     *
     * @return array
     */
    public function filter_add_blur_hash_attributes( array $attr, \WP_Post $attachment ) : array {
        $media_id = $attachment->ID ?? 0;
        if( $media_id && $blur_hash_token = $this->get_blur_hash_token( $media_id ) ) {
            $attr['data-blurhash'] = $blur_hash_token;
        }

        return $attr;
    }

    /**
     * Get Blurhash token of an image
     *
     * @param int $media_id
     *
     * @return string
     */
    private function get_blur_hash_token( int $media_id ) : string {
        $blur_hash_code = '';
        if( $media_id <= 0 ) {
            return $blur_hash_code;
        }

        if( empty( $blur_hash_code = (string) get_post_meta( $media_id, 'wp_blur_hash_token', true ) )
            && ! wp_next_scheduled( 'cron_blur_hash_token', $media_id )
        ) {
            wp_schedule_single_event( time() + 1, 'cron_blur_hash_token', [ $media_id ] );
        }

        return $blur_hash_code;
    }
}