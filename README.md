# Grav Page Statistics Plugin

The **Page Statistics Plugin** for [Grav](http://github.com/getgrav/grav) adds the ability to add simple page rating.

# Usage

Add `{% include 'partials/stats.html.twig' %}` to the template file where you want to add comments.

For example, in Antimatter, in `templates/item.html.twig`:

```twig
{% embed 'partials/base.html.twig' %}

    {% block content %}
        {% if config.plugins.breadcrumbs.enabled %}
            {% include 'partials/breadcrumbs.html.twig' %}
        {% endif %}

        <div class="blog-content-item grid pure-g-r">
            <div id="item" class="block pure-u-2-3">
                {% include 'partials/blog_item.html.twig' with {'blog':page.parent, 'truncate':false} %}
            </div>
            <div id="sidebar" class="block size-1-3 pure-u-1-3">
                {% include 'partials/sidebar.html.twig' with {'blog':page.parent} %}
            </div>
        </div>

        {% include 'partials/stats.html.twig' %}
    {% endblock %}

{% endembed %}
```

The like and dislike form/button will appear on the blog post items matching the enabled routes.

To set the enabled routes, create a `user/config/plugins/statistics.yaml` file, copy in it the contents of `user/plugins/comments/statistics.yaml` and edit the `enable_on_routes` and `disable_on_routes` options according to your needs.

# Where are the likes stored?

In the `log://popularity/likes.json` folder.
