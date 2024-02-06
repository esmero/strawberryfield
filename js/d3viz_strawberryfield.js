(function ($, Drupal, drupalSettings, d3, d3plus, once) {
    {
        'use strict';

        Drupal.strawberryfield_collapsible_tree = function(data, selector) {

            const root = d3.hierarchy(data);
            const margin = {top: 40, right: 100, bottom: 40, left: 120};
            const width = 940 - margin.right - margin.left;
            const height = 1024 - margin.top - margin.bottom;
            const dx = 10;
            const dy = width/2;

            var tooltip = d3.select(selector)
                .append("div")
                .style("position", "absolute")
                .style("z-index", "100")
                .style("visibility", "hidden")
                .style("background", "#CCC")
                .style("width", "auto")
                .style("height", "auto")
                .style("padding", "1em")
                .text("Info");

            var diagonal = d3.linkHorizontal().x(d => d.y).y(d => d.x);
            root.x0 = height/2;
            root.y0 = margin.right/2;
            var tree = d3.tree().size([height - margin.top - margin.bottom, width - margin.left - margin.right]);


            root.descendants().forEach((d, i) => {
                d.id = i;
                d._children = d.children;
                if (d.depth > 1 ) d.children = null;
            });

            const svg = d3.select(selector).append("svg")
                .attr("viewBox", [0, 0, width, height])
                .style("font", "0.37em sans-serif")
                .style("user-select", "none");

            var columns = svg.append("g").attr("transform", "translate(40,40)");

            const gLink = svg.append("g")
                .attr("fill", "none")
                .attr("stroke", "#555")
                .attr("stroke-opacity", 0.4)
                .attr("stroke-width", 1.5)

            const gNode = svg.append("g")
                .attr("cursor", "pointer")
                .attr("pointer-events", "all")

            function update(source) {
                const duration = d3.event && d3.event.altKey ? 2500 : 350;
                const nodes = root.descendants().reverse();
                // Compute the tree layout.
                tree(root);
                const links = root.links();

                let left = root;
                let right = root;
                root.eachBefore(node => {
                    if (node.x < left.x) left = node;
                    if (node.x > right.x) right = node;
                });

                const height = right.x - left.x + margin.top + margin.bottom;

                const transition = svg.transition()
                    .duration(duration)
                    .attr("viewBox", [ -margin.left , left.x - margin.top, width, height])
                    .tween("resize", window.ResizeObserver ? null : () => () => svg.dispatch("toggle"));

                // Update the nodes…
                const node = gNode.selectAll("g")
                    .data(nodes, d => d.id);

                // Enter any new nodes at the parent's previous position.
                const nodeEnter = node.enter().append("g")
                    .attr("transform", d => `translate(${source.y0},${source.x0})`)
                    .attr("fill-opacity", 0)
                    .attr("stroke-opacity", 0);

                nodeEnter.append("circle")
                    .attr("r", dx/2)
                    .attr("fill", d => d._children ? "#EEE" : "#FFF")
                    .attr("stroke-width",  d => d._children ? 2 : 1)
                    .attr("stroke", d => d._children ? "steelblue" : "#999" )
                    .on("click", (event, d) => {
                        d.children = d.children ? null : d._children;
                        update(d);
                    })
                    .on("mouseover", function(event, d){
                        if (d.data.hasOwnProperty('values') && d.data.values) {
                            tooltip.html(d.data.values);
                            return tooltip.style("visibility", "visible");
                        }
                    })
                    .on("mousemove", function(event, d){return tooltip.style("top", (event.pageY-10)+"px").style("left",(event.pageX+10)+"px");})
                    .on("mouseout", function(event, d){return tooltip.style("visibility", "hidden");});


                nodeEnter.append("text")
                    .attr("dy", "0.31em")
                    .attr("class", "nodelabel")
                    .attr("x", d => d._children ? -10 : 10)
                    .attr("text-anchor", d => d._children ? "end" : "start")
                    .text(d => d.data.name)
                    .call(wrap, 200)
                    .clone(true).lower()
                    .attr("class", "nodelabelbackground")
                    .attr("stroke-linejoin", "round")
                    .attr("stroke-width", 3)
                    .attr("stroke", "white");

                nodeEnter.append("text")
                    .attr("dy", "0.31em")
                    .style('fill', 'darkOrange')
                    .attr("x", d => d._children ? 10 : -10)
                    .attr("text-anchor", d => d._children ? "start" : "end")
                    .call(openlink);

                // Transition nodes to their new position.
                const nodeUpdate = node.merge(nodeEnter).transition(transition)
                    .attr("transform", d => `translate(${d.y},${d.x})`)
                    .attr("fill-opacity", 1)
                    .attr("stroke-opacity", 1);

                // Transition exiting nodes to the parent's new position.
                const nodeExit = node.exit().transition(transition).remove()
                    .attr("transform", d => `translate(${source.y},${source.x})`)
                    .attr("fill-opacity", 0)
                    .attr("stroke-opacity", 0);

                // Update the links…
                const link = gLink.selectAll("path")
                    .data(links, d => d.target.id);

                // Enter any new links at the parent's previous position.
                const linkEnter = link.enter().append("path")
                    .attr("d", d => {
                        const o = {x: source.x0, y: source.y0};
                        return diagonal({source: o, target: o});
                    });

                // Transition links to their new position.
                link.merge(linkEnter).transition(transition)
                    .attr("stroke", "#ccc")
                    .attr("stroke-width", 1)
                    .attr("d", diagonal);

                // Transition exiting nodes to the parent's new position.
                link.exit().transition(transition).remove()
                    .attr("d", d => {
                        const o = {x: source.x, y: source.y};
                        return diagonal({source: o, target: o});
                    });
                // Stash the old positions for transition.
                root.eachBefore(d => {
                    d.x0 = d.x;
                    d.y0 = d.y;
                });
            }

            function openlink(node) {
                node.each(function () {
                    var text = d3.select(this);
                    var url = text.data()[0].data.url;
                    if (url) {
                        text.text('Edit');
                        text.on('click', function () {
                            console.log('open tab')
                            window.open(
                                url,
                                '_blank' // <- This is what makes it open in a new window.
                            );
                        });
                    }
                });
            }

            // Wraps text by splitting attached text, clearing and adding tspans.
            function wrap(text, width) {
                text.each(function () {
                        var text = d3.select(this);
                        var lines = d3plus.textWrap().fontFamily('Arial').fontSize(14).maxLines(5)(text.text()).lines;
                        lines = lines.length > 0 ? lines : [text.text()];
                        var line = [],
                        lineNumber = 0,
                        lineHeight = 1.2, // ems
                        x = text.attr("x"),
                        y = text.attr("y"),
                        dy = parseFloat(text.attr("dy"));
                        text.text(null);
                    while (line = lines.shift()) {
                        let tspan;
                        tspan = text.append("tspan")
                                .attr("x", x)
                                .attr("y", y)
                                .style('fill', 'black')
                                .attr("dy", (lineNumber++ * lineHeight +dy) + "em")
                                .text(line);
                    }
                });
            }

            update(root);
            return svg.node();
        };


        if (typeof module !== 'undefined' && module.exports) {
            module.exports = Drupal.strawberryfield_collapsible_tree;
        } else {
            d3.collapsible_tree = Drupal.strawberryfield_collapsible_tree;
        }

        Drupal.behaviors.d3viz = {
          attach: function (context, settings) {
            const visualizedtoAttach = once('sbf_d3viz', '#visualized');
            $(visualizedtoAttach).each(function (index, value) {
              var element_id = $(this).attr("id");
              const data = JSON.parse(drupalSettings.strawberry_keyname_provider);
              return new Promise(function () {
                $('#visualized').empty();
                d3.collapsible_tree(data, '#visualized');
              });
            })
          },
          detach: function detach(context, settings, trigger) {
            // Thanks debugger, if not i would have ended using "unload"
            if (trigger === 'serialize') {
              const visualizedtoDeattach = once.remove('sbf_d3viz', '#visualized');
            }
          }
        }
    }
})(jQuery, Drupal, drupalSettings, d3, d3plus, once);


